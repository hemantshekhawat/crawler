<?php

// TODO: write a helpful usage.
$usage = function() use ($argv) {
  print "Usage: " . basename($argv[0]) . " [OPTIONS] [URL]\n";
  print "\n";
  print "-d --depth=NUMBER        set maximum depth.\n";
  print "-D --dom=LIST            set which dom elements and which attributes will be used to collect urls.\n";
  print "-f --follow=REGEXP       \n";
  print "-p --print=STRING        \n";
  print "--sleep=SECONDS          \n";
  print "--domains=LIST_CSV       \n";
  print "--user-agent=STRING      \n";
  print "--robots                 \n";
  print "--help                   print this help.\n";
  print "\n";
};

// No arguments.
if ($argc == 1) {
  $usage();
  exit;
}

// Ensure last argument is an url, not an option.
if ($argv[$argc - 1][0] == '-') {
  $usage();
  exit;
}

$options = getopt('d:D:f:np:s:', array(
  'help',
  'depth:',
  'dom:',
  'follow:',
  'print:',
  'sleep:',
  'domains:',
  'user-agent',
  'robots',
));

$url = $argv[$argc - 1];
$url_parsed = parse_url($url);

$domains = array();
$domains[] = $url_parsed['host'];

$check_robots = FALSE;
$user_agent = FALSE;
$sleep = 0;
$maxdepth = 0;
$follow = '.*';
$follow_negate = FALSE;
$print = "\$header_status\t\$url\t\$parent";
$dom =  array(
  'a' => array('href'),
  'form' => array('action'),
  'link' => array('href'),
  'img' => array('src'),
  'iframe' => array('src'),
  'script' => array('src'),
  'object' => array('src'),
  'video' => array('src'),
  'audio' => array('src'),
);

foreach ($options as $key => $value) {
  switch ($key) {
    case 'help':
      $usage();
      exit;

    case 'd':
    case 'depth':
      $maxdepth = $value;
      break;

    case 'D':
    case 'dom':
      $dom = array();
      foreach (array_map('trim', explode(',', $value)) as $data) {
        preg_match('/^(.+?)\[(.+)\]$/', $data, $matches);
        $tag = $matches[1];
        $attrs = explode('|', $matches[2]);
        $dom[$tag] = $attrs;
      }
      break;

    case 'p':
    case 'print':
      $print = strtr($value, array('\t' => "\t", '\n' => "\n"));
      break;

    case 'n':
      $follow_negate = TRUE;
      break;

    case 'follow':
      $follow = $value;
      break;

    case 'sleep':
      $sleep = $value;
      break;

    case 'domains':
      $domains = array_map('trim', explode(',', $value));
      break;

    case 'user-agent':
      $user_agent = $value;
      break;

    case 'robots':
      $check_robots = TRUE;
      break;
  }
}

// Start the crawling algorithm.
$visited = array();

$urls = array();

// Initial state: one url, with no parent and no depth.
$urls[] = array($url, '', 0);

while (!empty($urls)) {
  list($url, $parent, $depth) = array_pop($urls);

  if ($maxdepth && $depth > $maxdepth) {
    continue;
  }

  $url = rtrim($url, '/');
  $url_parsed = parse_url($url);

  $headers = array();

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
  curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers) {
    if (preg_match('/^(.+?):(.+)$/', $header, $matches)) {
      $headers[$matches[1]] = trim($matches[2]);
    }
    else if (trim($header)) {
      $headers['status'] = trim($header);
    }

    return strlen($header);
  });

  if ($user_agent) {
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
  }

  $body = curl_exec($ch);
  curl_close($ch);

  $visited[$url] = TRUE;

  if ($print) {
    $replacements = array();
    $replacements['$url'] = $url;
    $replacements['$parent'] = $parent;
    $replacements['$depth'] = $depth;
    foreach ($headers as $name => $value) {
      $replacements['$header_' . strtolower(str_replace('-', '_', $name))] = $value;
    }

    $print_value = $print;
    foreach ($replacements as $name => $value) {
      $print_value = str_replace($name, $value, $print_value);
    }

    print "$print_value\n";
  }

  // Sleep if we want to less stress the server.
  if ($sleep) {
    sleep($sleep);
  }

  // User could be interested in collecting urls only in specific domains.
  if (!in_array($url_parsed['host'], $domains)) {
    continue;
  }

  // Find links only in html pages.
  if (strpos($headers['Content-Type'], 'text/html') !== 0) {
    continue;
  }

  // If simulating a real crawler, skip pages with "nofollow".
  if ($check_robots) {
    $nofollow = preg_match('/<meta.*?name="robots".*content=".*?nofollow.*?">/i', $body);

    if ($nofollow) {
      continue;
    }
  }

  // TODO: look for base tag too.
  $base_url = $url_parsed['scheme'] . '://' . $url_parsed['host'] . (isset($url_parsed['path']) ? $url_parsed['path'] : '');

  // Collect urls, by extracting interesting dom elements.
  foreach ($dom as $tag => $attrs) {
    preg_match_all('/<' . $tag . '[^>]+(' . join('|', $attrs) . ')="(.+?)".*?>/i', $body, $matches);

    foreach ($matches[2] as $index => $deep) {
      // If simulating a real crawler, skip links with "nofollow".
      if ($check_robots) {
        $nofollow = ($tag == 'a' && preg_match('/rel="nofollow"/i', $matches[0][$index]));

        if ($nofollow) {
          continue;
        }
      }

      $deep = rtrim($deep, '/');
      $deep_parsed = parse_url($deep);

      // E.g fragment only urls.
      if (!isset($deep_parsed['scheme']) &&
          !isset($deep_parsed['host']) &&
          (!isset($deep_parsed['path']) || !$deep_parsed['path']) &&
          !isset($deep_parsed['query'])) {
        continue;
      }

      // Relative urls must be become absolute.
      if (!isset($deep_parsed['scheme']) && !isset($deep_parsed['host'])) {
        if ($deep_parsed['path'][0] == '/') {
          // Just prepend host name.
          $deep = $url_parsed['scheme'] . '://' . $url_parsed['host'] . '/' . ltrim($deep, '/');
        }
        else {
          // Expand ../
          $tmp = $deep;
          $base = $base_url;
          while (substr($tmp, 0, 3) == '../') {
            $tmp = substr($tmp, 3);
            $base = dirname($base);
          }

          $deep = $base . '/' . ltrim($tmp, '/');
        }

        // Url has been modified, re-parse it.
        $deep_parsed = parse_url($deep);
      }

      // Skip "unfollowable" protocols (e.g javascript, mailto, tel, skype).
      $followable_protocols = array('http', 'https', 'ftp');
      if (!in_array($deep_parsed['scheme'], $followable_protocols)) {
        continue;
      }

      // Already visited or already in list.
      if (isset($visited[$deep]) || isset($urls[$deep])) {
        continue;
      }

      // Decide whether following current url.
      $follow_re_matches = preg_match('~' . $follow . '~i', $deep);
      $accept = $follow_re_matches;
      if ($follow_negate) {
        $accept = !$follow_re_matches;
      }

      if (!$accept) {
        continue;
      }

      // Push collected url with parent and depth.
      $urls[$deep] = array($deep, $url, $depth + 1);
    }
  }
}
