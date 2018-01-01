<?php
/**
 * staticMapLite 0.3.1
 *
 * modified by ketoor
 *
 * Copyright 2009 Gerhard Koch
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *	 http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author Gerhard Koch <gerhard.koch AT ymail.com>
 *
 * USAGE:
 *
 *  staticmap.php?center=40.714728,-73.998672&zoom=14&size=512x512&maptype=mapnik&markers=40.702147,-74.015794,blues|40.711614,-74.012318,greeng|40.718217,-73.998284,redc
 *
 */

#error_reporting(0);
#ini_set('display_errors', 'off');

Class staticMapLite
{
	protected $tileSize = 256;
	protected $tileSrcUrl = 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png';

	protected $markerBaseDir = 'images/markers';
	protected $osmLogo;# = 'images/osm_logo.png';

	protected $markerPrototype = array(
		'filename' => 'ol-marker.png',
		'shadow' => '../marker_shadow.png',
		'offsetImage' => '-10,-25',
		'offsetShadow' => '-1,-13'
	);


	protected $useTileCache = true;
	protected $tileCacheBaseDir = '../cache/tiles';

	protected $useMapCache = true;
	protected $mapCacheBaseDir = '../cache/maps';
	protected $mapCacheID = '';
	protected $mapCacheFile = '';
	protected $mapCacheExtension = 'png';

	protected $width = 375;
	protected $height = 250;

	protected $zoom, $lat, $lon, $markers, $image, $maptype;

	protected $maps = array(
		# city
		array(
			'zoom' => 9,
			'width' => 125,
			'height' => 250,
			'x' => 0,
			'y' => 0,
		),
		# district
		array(
			'zoom' => 15,
			'width' => 246,
			'height' => 123,
			'x' => 129,
			'y' => 0,
		),
		# point
		array(
			'zoom' => 17,
			'width' => 246,
			'height' => 123,
			'x' => 129,
			'y' => 127,
		),
	);

	public function __construct()
	{
		$this->lat = 0;
		$this->lon = 0;
	}

	public function parseParams()
	{
		global $_GET;
		$this->parseLiteParams();
	}

	public function parseLiteParams()
	{
		// get lat and lon from GET paramter
		list($this->lat, $this->lon) = explode(',', $_GET['center']);
		$this->lat = floatval($this->lat);
		$this->lon = floatval($this->lon);
	}

	public function lonToTile($long, $zoom)
	{
		return (($long + 180) / 360) * pow(2, $zoom);
	}

	public function latToTile($lat, $zoom)
	{
		return (1 - log(tan($lat * pi() / 180) + 1 / cos($lat * pi() / 180)) / pi()) / 2 * pow(2, $zoom);
	}

	public function createBaseMap()
	{
		$this->image = imagecreatetruecolor($this->width, $this->height);
		foreach ($this->maps as $map) {
			$image = imagecreatetruecolor($map['width'], $map['height']);
			$centerX = $this->lonToTile($this->lon, $map['zoom']);
			$centerY = $this->latToTile($this->lat, $map['zoom']);
			$startX = floor($centerX - ($map['width'] / $this->tileSize) / 2);
			$startY = floor($centerY - ($map['height'] / $this->tileSize) / 2);
			$endX = ceil($centerX + ($map['width'] / $this->tileSize) / 2);
			$endY = ceil($centerY + ($map['height'] / $this->tileSize) / 2);
			$offsetX = -floor(($centerX - floor($centerX)) * $this->tileSize);
			$offsetY = -floor(($centerY - floor($centerY)) * $this->tileSize);
			$offsetX += floor($map['width'] / 2);
			$offsetY += floor($map['height'] / 2);
			$offsetX += floor($startX - floor($centerX)) * $this->tileSize;
			$offsetY += floor($startY - floor($centerY)) * $this->tileSize;

			for ($x = $startX; $x <= $endX; $x++) {
				for ($y = $startY; $y <= $endY; $y++) {
					$url = str_replace(array('{Z}', '{X}', '{Y}'), array($map['zoom'], $x, $y), $this->tileSrcUrl);
					$tileData = $this->fetchTile($url);
					if ($tileData) {
						$tileImage = imagecreatefromstring($tileData);
					} else {
						$tileImage = imagecreate($this->tileSize, $this->tileSize);
						$color = imagecolorallocate($tileImage, 255, 255, 255);
						@imagestring($tileImage, 1, 127, 127, 'err', $color);
					}
					$destX = ($x - $startX) * $this->tileSize + $offsetX;
					$destY = ($y - $startY) * $this->tileSize + $offsetY;
					imagecopy($image, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
				}
			}
			imagecopy($this->image, $image, $map['x'], $map['y'], 0, 0, $map['width'], $map['height']);
			$this->placeMarker($map['x'] + $map['width'] / 2, $map['y'] + $map['height'] / 2);
		}
	}

	public function placeMarker($destX, $destY)
	{
		$markerFilename = $this->markerPrototype['filename'];
		if ($this->markerPrototype['offsetImage']) {
			list($markerImageOffsetX, $markerImageOffsetY) = explode(",", $this->markerPrototype['offsetImage']);
		}
		$markerShadow = $this->markerPrototype['shadow'];
		if ($markerShadow) {
			list($markerShadowOffsetX, $markerShadowOffsetY) = explode(",", $this->markerPrototype['offsetShadow']);
		}

		// create img resource
		if (file_exists($this->markerBaseDir . '/' . $markerFilename)) {
			$markerImg = imagecreatefrompng($this->markerBaseDir . '/' . $markerFilename);
		} else {
			return;
		}

		// check for shadow + create shadow recource
		if ($markerShadow && file_exists($this->markerBaseDir . '/' . $markerShadow)) {
			$markerShadowImg = imagecreatefrompng($this->markerBaseDir . '/' . $markerShadow);
		}

		// copy shadow on basemap
		if ($markerShadow && $markerShadowImg) {
			imagecopy($this->image, $markerShadowImg, $destX + intval($markerShadowOffsetX), $destY + intval($markerShadowOffsetY),
				0, 0, imagesx($markerShadowImg), imagesy($markerShadowImg));
		}

		// copy marker on basemap above shadow
		imagecopy($this->image, $markerImg, $destX + intval($markerImageOffsetX), $destY + intval($markerImageOffsetY),
			0, 0, imagesx($markerImg), imagesy($markerImg));
	}

	public function tileUrlToFilename($url)
	{
		return $this->tileCacheBaseDir . "/" . str_replace(array('http://'), '', $url);
	}

	public function checkTileCache($url)
	{
		$filename = $this->tileUrlToFilename($url);
		if (file_exists($filename)) {
			return file_get_contents($filename);
		}
	}

	public function checkMapCache()
	{
		$this->mapCacheID = md5($this->serializeParams());
		$filename = $this->mapCacheIDToFilename();
		if (file_exists($filename)) return true;
	}

	public function serializeParams()
	{
		return join("&", array($this->lat, $this->lon));
	}

	public function mapCacheIDToFilename()
	{
		if (!$this->mapCacheFile) {
			$this->mapCacheFile = $this->mapCacheBaseDir . "/cache_" . substr($this->mapCacheID, 0, 2) . "/" . substr($this->mapCacheID, 2, 2) . "/" . substr($this->mapCacheID, 4);
		}
		return $this->mapCacheFile . "." . $this->mapCacheExtension;
	}


	public function mkdir_recursive($pathname, $mode)
	{
		is_dir(dirname($pathname)) || $this->mkdir_recursive(dirname($pathname), $mode);
		return is_dir($pathname) || @mkdir($pathname, $mode);
	}

	public function writeTileToCache($url, $data)
	{
		$filename = $this->tileUrlToFilename($url);
		$this->mkdir_recursive(dirname($filename), 0777);
		file_put_contents($filename, $data);
	}

	public function fetchTile($url)
	{
		if ($this->useTileCache && ($cached = $this->checkTileCache($url))) return $cached;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0");
		curl_setopt($ch, CURLOPT_URL, $url);
		$tile = curl_exec($ch);
		curl_close($ch);
		if ($tile && $this->useTileCache) {
			$this->writeTileToCache($url, $tile);
		}
		return $tile;

	}

	public function copyrightNotice()
	{
		$logoImg = imagecreatefrompng($this->osmLogo);
		imagecopy($this->image, $logoImg, imagesx($this->image) - imagesx($logoImg), imagesy($this->image) - imagesy($logoImg), 0, 0, imagesx($logoImg), imagesy($logoImg));

	}

	public function sendHeader()
	{
		header('Content-Type: image/png');
		$expires = 60 * 60 * 24 * 14;
		header("Pragma: public");
		header("Cache-Control: maxage=" . $expires);
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
	}

	public function makeMap()
	{
		$this->createBaseMap();
		if (count($this->markers)) $this->placeMarkers();
		if ($this->osmLogo) $this->copyrightNotice();
	}

	public function showMap()
	{
		$this->parseParams();
		if ($this->useMapCache) {
			// use map cache, so check cache for map
			if (!$this->checkMapCache()) {
				// map is not in cache, needs to be build
				$this->makeMap();
				$this->mkdir_recursive(dirname($this->mapCacheIDToFilename()), 0777);
				imagepng($this->image, $this->mapCacheIDToFilename(), 9);
				$this->sendHeader();
				if (file_exists($this->mapCacheIDToFilename())) {
					return file_get_contents($this->mapCacheIDToFilename());
				} else {
					return imagepng($this->image);
				}
			} else {
				// map is in cache
				$this->sendHeader();
				return file_get_contents($this->mapCacheIDToFilename());
			}

		} else {
			// no cache, make map, send headers and deliver png
			$this->makeMap();
			$this->sendHeader();
			return imagepng($this->image);

		}
	}

}

$map = new staticMapLite();
print $map->showMap();
