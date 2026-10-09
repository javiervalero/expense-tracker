<?php

declare(strict_types=1);

if (!function_exists('gd_info')) {
    echo "gd_info missing\n";
    exit(1);
}
foreach (gd_info() as $key => $value) {
    echo $key, '=', is_bool($value) ? (int) $value : $value, "\n";
}
$img = imagecreatetruecolor(32, 24);
imagefilledrectangle($img, 0, 0, 31, 23, (int) imagecolorallocate($img, 200, 30, 30));
$tmp = sys_get_temp_dir();
foreach (['webp' => 'imagewebp', 'jpeg' => 'imagejpeg', 'png' => 'imagepng'] as $ext => $writer) {
    $path = "$tmp/sample.$ext";
    $writer($img, $path);
    $info = getimagesize($path);
    echo "wrote $ext ", filesize($path), ' bytes, getimagesize=', $info === false ? 'false' : $info['mime'], "\n";
}
$read = imagecreatefromwebp("$tmp/sample.webp");
if ($read === false || imagesx($read) !== 32 || imagesy($read) !== 24) {
    echo "webp_roundtrip=FAILED\n";
    exit(1);
}
echo 'webp_roundtrip=ok (', imagesx($read), 'x', imagesy($read), ")\n";
