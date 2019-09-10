<?php
/**
 * Maps for Craft CMS
 *
 * @link      https://ethercreative.co.uk
 * @copyright Copyright (c) 2019 Ether Creative
 */

namespace ether\simplemap\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\Template;
use craft\web\View;
use enshrined\svgSanitize\Sanitizer;
use ether\simplemap\enums\MapTiles;
use ether\simplemap\models\EmbedOptions;
use ether\simplemap\models\Settings;
use ether\simplemap\SimpleMap;
use yii\base\InvalidConfigException;

/**
 * Class EmbedService
 *
 * @author  Ether Creative
 * @package ether\simplemap\services
 */
class EmbedService extends Component
{

	// Constants
	// =========================================================================

	const JSON_OPTS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	// Embed
	// =========================================================================

	/**
	 * @param array $options
	 *
	 * @return string|void
	 * @throws \yii\base\InvalidConfigException
	 */
	public function embed ($options = [])
	{
		if (SimpleMap::v(SimpleMap::EDITION_LITE))
			return 'Sorry, embed maps are a Maps Pro feature!';

		$options = new EmbedOptions($options);

		/** @var Settings $settings */
		$settings = SimpleMap::getInstance()->getSettings();

		switch ($settings->mapTiles)
		{
			case MapTiles::GoogleHybrid:
			case MapTiles::GoogleRoadmap:
			case MapTiles::GoogleTerrain:
				$code = $this->_embedGoogle($options, $settings);
				break;
			case MapTiles::MapKitHybrid:
			case MapTiles::MapKitMutedStandard:
			case MapTiles::MapKitSatellite:
			case MapTiles::MapKitStandard:
				$code = $this->_embedApple($options, $settings);
				break;
			case MapTiles::MapboxDark:
			case MapTiles::MapboxLight:
			case MapTiles::MapboxOutdoors:
			case MapTiles::MapboxStreets:
				$code = $this->_embedMapbox($options, $settings);
				break;
			case MapTiles::HereHybrid:
			case MapTiles::HereNormalDay:
			case MapTiles::HereNormalDayGrey:
			case MapTiles::HereNormalDayTransit:
			case MapTiles::HerePedestrian:
			case MapTiles::HereReduced:
			case MapTiles::HereSatellite:
			case MapTiles::HereTerrain:
				$code = $this->_embedHere($options, $settings);
				break;
			default:
				$code = $this->_embedDefault($options);
		}

		return Template::raw($code);
	}

	// Embed-ers
	// =========================================================================

	private function _embedGoogle (EmbedOptions $options, Settings $settings)
	{
		$view = Craft::$app->getView();
		$callbackName = 'init_' . $options->id;

		switch ($settings->mapTiles)
		{
			case MapTiles::GoogleRoadmap:
				$mapTypeId = 'roadmap';
				break;
			case MapTiles::GoogleTerrain:
				$mapTypeId = 'terrain';
				break;
			case MapTiles::GoogleHybrid:
			default:
				$mapTypeId = 'hybrid';
		}

		$formattedOptions = Json::encode(
			array_merge(
				$options->options,
				[
					'center'    => $options->getCenter(),
					'zoom'      => $options->zoom,
					'mapTypeId' => $mapTypeId,
				]
			),
			self::JSON_OPTS
		);

		$formattedMarkers = [];

		foreach ($options->markers as $marker)
			$formattedMarkers[] = [
				'position' => $marker->getCenter(),
				'label' => $marker->label,
				// TODO: Add custom colour support
			];

		$formattedMarkers = Json::encode(
			$formattedMarkers,
			self::JSON_OPTS
		);

		$params = http_build_query([
			'key' => $settings->mapToken,
			'callback' => $callbackName,
		]);

		$this->_js(
			'https://maps.googleapis.com/maps/api/js?' . $params,
			['async' => '', 'defer' => '']
		);

		$js = <<<JS
let {$options->id};

function {$callbackName} () {
	{$options->id} = new google.maps.Map(document.getElementById('{$options->id}'), $formattedOptions);
	
	{$options->id}._markers = [];
	{$formattedMarkers}.forEach(function (marker) {
		marker.map = {$options->id};
		{$options->id}._markers.push(new google.maps.Marker(marker));
	});
}
JS;

		$css = <<<CSS
#{$options->id} {
	width: {$options->width}px;
	height: {$options->height}px;
}
CSS;


		$view->registerJs($js, View::POS_END);
		$view->registerCss($css);

		return '<div id="' . $options->id . '"></div>';
	}

	private function _embedApple (EmbedOptions $options, Settings $settings)
	{
		$view = Craft::$app->getView();

		$token = GeoService::getToken(
			$settings->mapToken,
			$settings->mapTiles
		);
		$latLng = implode(', ', array_values($options->getCenter()));

		$formattedOptions = Json::encode(
			array_merge(
				$options->options,
				[
					'center'         => '##CENTER##',
					'cameraDistance' => '##ZOOM##',
					'mapType'        => '##MAPTYPE##',
				]
			),
			self::JSON_OPTS
		);

		switch ($settings->mapTiles)
		{
			default:
			case MapTiles::MapKitStandard:
				$type = 'Standard';
				break;
			case MapTiles::MapKitSatellite:
				$type = 'Satellite';
				break;
			case MapTiles::MapKitMutedStandard:
				$type = 'MutedStandard';
				break;
			case MapTiles::MapKitHybrid:
				$type = 'Hybrid';
				break;
		}

		$formattedOptions = str_replace([
			'"##CENTER##"',
			'"##ZOOM##"',
			'"##MAPTYPE##"',
		], [
			'new mapkit.Coordinate(' . $latLng . ')',
			'156543.03392 * Math.cos(' . $options->getCenter()['lat'] . ' * Math.PI / 180) / Math.pow(2, ' . $options->zoom . ') * ' . $options->width,
			'mapkit.Map.MapTypes.' . $type,
		], $formattedOptions);

		$formattedMarkers = [];

		foreach ($options->markers as $marker)
			$formattedMarkers[] = [
				'position' => array_values($marker->getCenter()),
				'label'    => $marker->label,
				'color'    => $marker->color,
			];

		$formattedMarkers = Json::encode(
			$formattedMarkers,
			self::JSON_OPTS
		);

		$initJs = <<<JS
mapkit.init({ authorizationCallback: function (done) { done('{$token}') } });
JS;


		$js = <<<JS
const {$options->id} = new mapkit.Map('{$options->id}', {$formattedOptions});
{$options->id}._markers = [];
{$formattedMarkers}.forEach(function (marker) {
	marker.position.unshift(null);
	const m = new mapkit.MarkerAnnotation(
		new (mapkit.Coordinate.bind.apply(mapkit.Coordinate, marker.position)), 
		{
			glyphText: marker.label || '',
			color: marker.color || '',
		}
	);
	{$options->id}._markers.push(m);
	{$options->id}.addAnnotation(m);
});
JS;

		$css = <<<CSS
#{$options->id} {
	width: {$options->width}px;
	height: {$options->height}px;
}
CSS;

		$this->_js('https://cdn.apple-mapkit.com/mk/5.x.x/mapkit.js');
		$view->registerJs($initJs, View::POS_END);
		$view->registerJs($js, View::POS_END);
		$view->registerCss($css);

		return '<div id="' . $options->id . '"></div>';
	}

	private function _embedMapbox (EmbedOptions $options, Settings $settings)
	{
		$view = Craft::$app->getView();

		switch ($settings->mapTiles)
		{
			default:
			case MapTiles::MapboxStreets:
				$type = 'streets-v11';
				break;
			case MapTiles::MapboxOutdoors:
				$type = 'outdoors-v11';
				break;
			case MapTiles::MapboxLight:
				$type = 'light-v9';
				break;
			case MapTiles::MapboxDark:
				$type = 'dark-v10';
				break;
		}

		$formattedOptions = Json::encode(
			array_merge(
				$options->options,
				[
					'container' => $options->id,
					'style' => 'mapbox://styles/mapbox/' . $type,
					'center' => array_reverse(array_values($options->getCenter())),
					'zoom' => $options->zoom,
				]
			),
			self::JSON_OPTS
		);

		$formattedMarkers = [];

		foreach ($options->markers as $marker)
			$formattedMarkers[] = [
				'position' => array_reverse(array_values($marker->getCenter())),
				// TODO: Add label support
				'color'    => $marker->color,
			];

		$formattedMarkers = Json::encode(
			$formattedMarkers,
			self::JSON_OPTS
		);

		$initJs = <<<JS
mapboxgl.accessToken = '{$settings->mapToken}';
JS;


		$js = <<<JS
const {$options->id} = new mapboxgl.Map({$formattedOptions});
{$options->id}._markers = [];
{$formattedMarkers}.forEach(function (marker) {
	{$options->id}._markers.push(
		new mapboxgl.Marker({ color: marker.color })
			.setLngLat(marker.position)
			.addTo({$options->id})
	);
});
JS;

		$css = <<<CSS
#{$options->id} {
	width: {$options->width}px;
	height: {$options->height}px;
}
CSS;

		$this->_js('https://api.tiles.mapbox.com/mapbox-gl-js/v1.3.1/mapbox-gl.js');
		$view->registerCssFile('https://api.tiles.mapbox.com/mapbox-gl-js/v1.3.1/mapbox-gl.css');
		$view->registerJs($initJs, View::POS_END);
		$view->registerJs($js, View::POS_END);
		$view->registerCss($css);

		return '<div id="' . $options->id . '"></div>';
	}

	private function _embedHere (EmbedOptions $options, Settings $settings)
	{
		if (!array_key_exists('apiKey', $settings->mapToken) || !$settings->mapToken['apiKey'])
			throw new InvalidConfigException('Missing HERE API Key');

		$view = Craft::$app->getView();
		$markerIcon = $this->_iconSvg();

		$formattedOptions = Json::encode(
			array_merge(
				$options->options,
				[
					'center'     => $options->getCenter(),
					'zoom'       => $options->zoom,
					'pixelRatio' => '##PIXELRATIO##',
				]
			),
			self::JSON_OPTS
		);

		$formattedOptions = str_replace([
			'"##PIXELRATIO##"',
		], [
			'window.devicePixelRatio || 1',
		], $formattedOptions);

		switch ($settings->mapTiles)
		{
			default:
			case MapTiles::HereReduced:
			case MapTiles::HerePedestrian:
			case MapTiles::HereNormalDay:
				$type = 'normal.map';
				break;
			case MapTiles::HereTerrain:
			case MapTiles::HereNormalDayGrey:
				$type = 'terrain.map';
				break;
			case MapTiles::HereNormalDayTransit:
				$type = 'normal.transit';
				break;
			case MapTiles::HereSatellite:
				$type = 'satellite.xbase';
				break;
			case MapTiles::HereHybrid:
				$type = 'satellite.map';
				break;
		}

		$formattedMarkers = [];

		foreach ($options->markers as $marker)
			$formattedMarkers[] = [
				'position' => $marker->getCenter(),
				'label'    => $marker->label ?: '',
				'color'    => $marker->color,
			];

		$formattedMarkers = Json::encode(
			$formattedMarkers,
			self::JSON_OPTS
		);

		$initJs = <<<JS
const HERE_platform = new H.service.Platform({ apikey: '{$settings->mapToken['apiKey']}' });
window.HERE_defaultLayers = HERE_platform.createDefaultLayers();
JS;

		$js = <<<JS
const {$options->id} = new H.Map(
	document.getElementById('{$options->id}'),
	window.HERE_defaultLayers.raster.{$type},
	{$formattedOptions}
);

{$options->id}._behaviour = new H.mapevents.Behavior(new H.mapevents.MapEvents({$options->id}));
{$options->id}._ui = H.ui.UI.createDefault({$options->id}, window.HERE_defaultLayers);
{$options->id}._markers = [];

{$formattedMarkers}.forEach(function (marker) {
	const m = new H.map.Marker(
		marker.position, 
		{
			icon: new H.map.Icon('{$markerIcon}'.replace('##FILL##', marker.color).replace('##LABEL##', marker.label)),
		}
	);
	{$options->id}._markers.push(m);
	{$options->id}.addObject(m);
});

window.addEventListener('resize', function () { {$options->id}.getViewPort().resize() });
JS;

		$css = <<<CSS
#{$options->id} {
	width: {$options->width}px;
	height: {$options->height}px;
}
CSS;

		$this->_js('https://js.api.here.com/v3/3.1/mapsjs-core.js');
		$this->_js('https://js.api.here.com/v3/3.1/mapsjs-service.js');
		$this->_js('https://js.api.here.com/v3/3.1/mapsjs-ui.js');
		$this->_js('https://js.api.here.com/v3/3.1/mapsjs-mapevents.js');
		$view->registerCssFile('https://js.api.here.com/v3/3.1/mapsjs-ui.css');
		$view->registerJs($initJs, View::POS_END);
		$view->registerJs($js, View::POS_END);
		$view->registerCss($css);

		return '<div id="' . $options->id . '"></div>';
	}

	private function _embedDefault (EmbedOptions $options)
	{
		//
	}

	// Helpers
	// =========================================================================

	/**
	 * @param string $url     - The URL to the JS file
	 * @param array  $options - An array of options to be used as attributes
	 * @param string $pre     - Will link rel="pre${pre}" if not null
	 */
	private function _js ($url, $options = [], $pre = 'connect')
	{
		$view = Craft::$app->getView();

		if ($pre)
		{
			$crossOrigin = !$this->_compareUrls(
				$url,
				Craft::$app->sites->currentSite->baseUrl
			);

			$view->registerLinkTag(
				[
					'rel'         => 'pre' . $pre,
					'href'        => $url,
					'as'          => 'script',
					'crossorigin' => $crossOrigin,
				]
			);
		}

		$view->registerScript(
			'',
			View::POS_END,
			array_merge(
				['src' => $url],
				$options
			),
			md5($url)
		);
	}

	private function _compareUrls ($a, $b)
	{
		$a = parse_url($a, PHP_URL_HOST);
		$b = parse_url($b, PHP_URL_HOST);

		return $this->_trim($a) === $this->_trim($b);
	}

	private function _trim ($str)
	{
		if (stripos($str, 'www.') === 0)
			return substr($str, 4);

		return $str;
	}

	private function _iconSvg ()
	{
		static $svg;

		if ($svg)
			return $svg;

		$svg = Craft::getAlias('@simplemap/resources/marker.svg');
		$svg = file_get_contents($svg);
		$svg = (new Sanitizer())->sanitize($svg);
		$svg = preg_replace('/<!--.*?-->\s*/s', '', $svg);
		$svg = preg_replace('/<title>.*?<\/title>\s*/is', '', $svg);
		$svg = preg_replace('/<desc>.*?<\/desc>\s*/is', '', $svg);
		$svg = preg_replace('/<\?xml.*?\?>/', '', $svg);
		$svg = preg_replace('/[\r\n]/', '', $svg);
		$svg = preg_replace('/[\s]{2,}/', '', $svg);

		return $svg;
	}

}