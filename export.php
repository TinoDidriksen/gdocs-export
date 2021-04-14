#!/usr/bin/env php
<?php

require_once __DIR__.'/google-api-client/vendor/autoload.php';

$client = new Google_Client();
$client->setAccessType('offline');
$client->setAuthConfig(__DIR__.'/client_secret.json');
$client->refreshToken(file_get_contents(__DIR__.'/refresh_token.txt'));
$client->setSubject('replace.me@gmail.com');
$client->addScope(Google_Service_Drive::DRIVE);

$service = new Google_Service_Drive($client);

$stamp = 0;
foreach (glob('/backup/google/*.json', GLOB_NOSORT) as $f) {
	$stamp = max($stamp, filemtime($f));
}
$stamp = date('Y-m-d', $stamp);
echo "Fetching files changed since {$stamp}\n";

$ignore = [
	'1vi3JBbhkrKftdL3v6p3RUNdfU8WUkI_ccA4UPHJkjR8' => true, // Spreadsheet: Current and Past Projects
	'1a3Fmj5lGYYsH-NpHuNkXfoUJ06xnMrDhQRuAOiNNp2A' => true, // Document: #lesswrong-prog
	];
$pt = '';
do {
	$files = $service->files->listFiles([
		'pageSize' => 1000,
		'q' => "sharedWithMe and modifiedTime>='{$stamp}' and (mimeType='application/vnd.google-apps.document' or mimeType='application/vnd.google-apps.spreadsheet' or mimeType='application/vnd.google-apps.presentation')",
		'pageToken' => $pt,
		]);
	$pt = $files->nextPageToken ?? '';
	foreach ($files->files as $f) {
		echo "Ignoring {$f->id}\t{$f->name}\n";
		$ignore[$f->id] = true;
	}
} while (!empty($pt));

$list = [];

$pt = '';
do {
	$files = $service->files->listFiles([
		'pageSize' => 1000, // (not sharedWithMe)
		'q' => "modifiedTime>='{$stamp}' and (mimeType='application/vnd.google-apps.document' or mimeType='application/vnd.google-apps.spreadsheet' or mimeType='application/vnd.google-apps.presentation')",
		'pageToken' => $pt,
		]);
	$pt = $files->nextPageToken ?? '';
	$list = array_merge($list, $files->files);
	fprintf(STDERR, "Found %u files\n", count($list));
} while (!empty($pt));

foreach ($list as $f) {
	if (!empty($ignore[$f->id])) {
		continue;
	}

	echo "Exporting {$f->id}\t{$f->name}\n";
	$map[$f->id] = "{$f->id}\t{$f->name}";

	$om = 'application/vnd.oasis.opendocument.text';
	$os = '.odt';
	if ($f->mimeType === 'application/vnd.google-apps.spreadsheet') {
		$om = 'application/x-vnd.oasis.opendocument.spreadsheet';
		$os = '.ods';
	}
	else if ($f->mimeType === 'application/vnd.google-apps.presentation') {
		$om = 'application/vnd.oasis.opendocument.presentation';
		$os = '.odp';
	}

	$doc = $service->files->export($f->id, $om, ['alt' => 'media']);
	$doc = $doc->getBody()->getContents();
	file_put_contents("/backup/google/{$f->id}{$os}", $doc);

	$p = get_object_vars($f);
	foreach ($p as $k => $v) {
		if ($v === null) {
			unset($p[$k]);
		}
	}
	file_put_contents("/backup/google/{$f->id}{$os}.json", json_encode($p));
}
