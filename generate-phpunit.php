<?php
/**
dash-phpunit-ja

Copyright (c) 2017 T.Takamatsu <takamatsu@tactical.jp>

This software is released under the MIT License.
http://opensource.org/licenses/mit-license.php
*/


//----------------------------------------
// Config
//----------------------------------------

// set your language (en/ja/zh_cn)
$cfg_lang = 'ja';
$cfg_ver  = 'latest';

// set true, if you want index area
$cfg_index  = true;

// set true, if you have font trouble with google open sans (e.g. Zeal on windows)
$cfg_nosans = true;


//----------------------------------------
//
// Main process
//
//----------------------------------------

// get manual html
exec('rm -rf PHPUnit.docset/Contents/Resources/');
//exec('mkdir -p PHPUnit.docset/Contents/Resources/');
mkdir('PHPUnit.docset/Contents/Resources/', 0777, true);
exec("wget -rkl1 https://phpunit.de/manual/current/{$cfg_lang}/index.html");
exec('mv ' . __DIR__ . "/phpunit.de/manual/current/{$cfg_lang} " . __DIR__ . '/PHPUnit.docset/Contents/Resources/Documents/');
exec('rm -rf ' . __DIR__ . '/phpunit.de/');

// get current version (if available)
$html = file_get_contents(__DIR__ . '/PHPUnit.docset/Contents/Resources/Documents/index.html');
$edit = false;

if (preg_match('#>(\d+(\.\d+)) \(<strong>stable</strong>\)</a>#', $html, $matches)) {
	$cfg_ver = $matches[1];
	echo "\nDetect version '{$cfg_ver}'. set as current ...\n";
}

// gen Info.plist
file_put_contents(__DIR__ . "/PHPUnit.docset/Contents/Info.plist", <<<ENDE
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleIdentifier</key>
	<string>phpunit-{$cfg_lang}</string>
	<key>CFBundleName</key>
	<string>PHPUnit {$cfg_ver}-{$cfg_lang}</string>
	<key>DocSetPlatformFamily</key>
	<string>phpunit</string>
	<key>isDashDocset</key>
	<true/>
	<key>dashIndexFilePath</key>
	<string>index.html</string>
</dict>
</plist>
ENDE
);
copy(__DIR__ . '/icon.png', __DIR__ . '/PHPUnit.docset/icon.png');

// gen docset
$dom = new DomDocument;
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

$db = new sqlite3(__DIR__ . '/PHPUnit.docset/Contents/Resources/docSet.dsidx');
$db->query('CREATE TABLE searchIndex(id INTEGER PRIMARY KEY, name TEXT, type TEXT, path TEXT)');
$db->query('CREATE UNIQUE INDEX anchor ON searchIndex (name, type, path)');

// remove google open sans font
if ($cfg_nosans) {
	$html = remove_googlefonts($html);
	$edit = true;
}
if (strpos($html, '<nav') !== false) {
	$html = remove_navibar($html, '<br />');
	$edit = true;
}
if ($edit) {
	file_put_contents(
		__DIR__ . '/PHPUnit.docset/Contents/Resources/Documents/index.html',
		$html
	);
}


// add links from the table of contents
echo "\nCreate search indexes ...\n\n";
$links = $edited = [];

foreach ($dom->getElementsByTagName('a') as $a) {
	$href = $a->getAttribute('href');
	$str  = substr($href, 0, 6);

	if ($str[0] == '.') {
		continue;
	}
	if ($str == 'https:' || !strncmp($str, 'http:', 5)) {
		continue;
	}

	$file = preg_replace('/#.*$/', '', $href);

	if (!isset($edited[$file]) && $file != 'index.html') {
		$html = file_get_contents(__DIR__ . "/PHPUnit.docset/Contents/Resources/Documents/{$file}");

		// remove google open sans font
		if ($cfg_nosans) {
			$html = remove_googlefonts($html);
		}

		// remove index area
		if (!$cfg_index && ($p = strpos($html, '<div class="col-md-4 col-lg-3">')) !== false) {
			if (($q = strpos($html, '<div class="col-md-8 col-lg-9">', $p + 1)) !== false) {
				$html = substr($html, 0, $p) . "<div style='padding: 2.0em'>" . substr($html, $q + 31);
			}
		}

		// remove comment area
		if (($p = strpos($html, '<div class="row"><div class="col-md-2"></div><div class="col-md-8">')) !== false) {
			if (($q = strpos($html, '</div><div class="col-md-2"></div></div>', $p + 1)) !== false) {
				$html = substr($html, 0, $p) . substr($html, $q + 51);
			}
		}

		// remove navi bar
		file_put_contents(
			__DIR__ . "/PHPUnit.docset/Contents/Resources/Documents/{$file}",
			remove_navibar($html)
		);
		$edited[$file] = true;
	}

	$name = trim(preg_replace('#\s+#u', ' ', preg_replace('#^[A-Z0-9-]+\.#u', '', $a->nodeValue)));

	if (empty($name)) {
		continue;
	}
	$class = 'Guide';

	if (substr($href, 0, 30) == 'writing-tests-for-phpunit.html' && strpos($name, '(') !== false) {
		$class = 'Function';
	}
	$links[$name] = true;
	$db->query("INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"{$name}\",\"{$class}\",\"{$href}\")");

	echo "{$name}\n";
}


// now go through some of the files to add pointers to inline documentation
foreach ([ 'appendixes.assertions', 'appendixes.annotations', 'incomplete-and-skipped-tests', 'test-doubles', 'writing-tests-for-phpunit' ] as $file) {
	$search = $replace = [];
	$html   = file_get_contents(__DIR__ . "/PHPUnit.docset/Contents/Resources/Documents/{$file}.html");

	@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

	foreach ($dom->getElementsByTagName('td') as $td) {
		if (!$td->firstChild) {
			continue;
		}
		if (strtolower($td->firstChild->nodeName) != 'code') {
			continue;
		}
		$name = $td->firstChild->nodeValue;

		if (!preg_match('#^([a-z_]+ )?([a-z0-9_]+\()#i', $name, $m)) {
			continue;
		}

		$name   = isset($m[2]) ? $m[2] : $m[1];
		$anchor = preg_replace('#[^a-z]#i', '', $name);
		$href   = "{$file}.html#{$anchor}";

		$search[]  = '<td align="left"><code class="literal">' . $m[0];
		$replace[] = '<td align="left"><code class="literal" style="white-space: normal" id="' . $anchor . '">' . $m[0];

		$name .= ')';
		// echo $name, ' -> ', $href, "\n";

		if (isset($links[$name])) {
			continue;
		}

		$db->query("INSERT OR IGNORE INTO searchIndex(name, type, path) VALUES (\"{$name}\",\"Function\",\"{$href}\")");
		echo "{$name}\n";
	}

	$html = str_replace($search, $replace, $html);
	file_put_contents(__DIR__ . "/PHPUnit.docset/Contents/Resources/Documents/{$file}.html", $html);
}

echo "\nPHPUnit docset created !\n";


//----------------------------------------
// Helper functions
//----------------------------------------

// remove navi bar
function remove_navibar($html, $alt = '') {
	if ($html && ($p = strpos($html, '<nav')) !== false) {
		if (($q = strpos($html, '</nav', $p + 1)) !== false) {
			$html = substr($html, 0, $p) . $alt . substr($html, $q + 6);
		}
	}

	return $html;
}

// remove google open sans font
function remove_googlefonts($html) {
	if ($html) {
		$html = preg_replace(
			'#\s+<link( rel="stylesheet")? href=("|\')http(s)?://fonts.googleapis.com/css\?family=Open\+Sans:.+>#i',
			'',
			$html
		);
	}

	return $html;
}


