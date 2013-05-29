<?php

/**
 * Usage: crawler --depth=3 --dom=a[href],img[src] -n --follow="\.(jpg|png|)$" --print="$url\t$parent,\$response_code".
 */
if ($argc == 1) {
  print "Usage: " . basename($argv[0]) . " <URL>\n";
  exit;
}

$options = getopt('d:D:f:np:s:', array(
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

  if ($sleep) {
    sleep($sleep);
  }

  // Follow only specified domains.
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

  // Collect urls to resources.
  foreach ($dom as $tag => $attrs) {
    preg_match_all('/<' . $tag . '[^>]+(' . join('|', $attrs) . ')="(.+?)".*?>/i', $body, $matches);

    foreach ($matches[2] as $index => $deep) {
      // If simulating a real crawler, skip links with "nofollow".
      if ($check_robots) {
        $nofollow = $tag == 'a' && preg_match('/rel="nofollow"/i', $matches[0][$index]);

        if ($nofollow) {
          continue;
        }
      }

      $deep = rtrim($deep, '/');
      $deep_parsed = parse_url($deep);

      // E.g skip only fragment urls.
      if (!isset($deep_parsed['path']) || !$deep_parsed['path']) {
        continue;
      }

      // Relative urls.
      if (!isset($deep_parsed['host']) && !isset($deep_parsed['scheme'])) {
        $deep = $url_parsed['scheme'] . '://' . $url_parsed['host'] . '/' . ltrim($deep, '/');
        $deep_parsed['scheme'] = $url_parsed['scheme'];
        $deep_parsed['host'] = $url_parsed['host'];
      }

      // Skip "unfollowable" protocols (e.g javascript, mailto).
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
