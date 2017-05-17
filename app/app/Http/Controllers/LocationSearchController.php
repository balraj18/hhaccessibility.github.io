<?php namespace App\Http\Controllers;

use App\LocationTag;
use App\Location;
use App\BaseUser;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;

function compareByDistance($location1, $location2)
{
	if ( $location1->distance < $location2->distance )
		return -1;
	else if ( $location1->distance === $location2->distance )
		return 0;
	else
		return 1;
}

function compareByRating($location1, $location2)
{
	if ( $location1->rating < $location2->rating )
		return -1;
	else if ( $location1->rating === $location2->rating )
		return 0;
	else
		return 1;
}

function compareByName($location1, $location2)
{
	return strcasecmp($location1->name, $location2->name);
}

function updateDistances(array $locations)
{
	$user = new BaseUser();
	$longitude = $user->getLongitude();
	$latitude = $user->getLatitude();
	foreach ($locations as $location)
	{
		$location->distance = BaseUser::getDirectDistance(
			$longitude, $latitude, $location->longitude, $location->latitude);
	}
}

function updateRatings(array $locations)
{
	foreach ($locations as $location)
	{
		$location->rating = $location->getAccessibilityRating('universal');
	}
}

function calcSum($numbers, $max)
{
	$total = 0;
	foreach ($numbers as $num)
	{
		$total += min($max, $num);
	}
	return $total;
}

function calculateBucketHeightLimit($buckets, $max)
{
	$lens = [];
	foreach ($buckets as $key => $bucket)
	{
		$len = count($bucket);
		// Lengths of 0 won't affect the result
		// so ignore them to keep things more efficient.
		if ( $len > 0 )
			$lens []= min($max, $len);
			// lengths greater than max won't affect the result so ignore the amount over max.
	}
	$numLens = count($lens);
	$maxResult = $max;
	$minResult = $max / $numLens;
	
	// Use a binary search technique to bracket down the range.
	while ( $minResult < $maxResult )
	{
		$midResult = floor(($maxResult + $minResult) / 2);
		$midSum = calcSum($lens, $midResult);
		if ( $midSum === $max ) {
			$minResult = $midResult;
			$maxResult = $midResult;
		}
		else if ( $midSum < $max )
		{
			$minResult = $midResult + 1;
		}
		else
		{
			$maxResult = $midResult;
		}
	}
	return $minResult;
}

function getLatitudeAndLongitudeRange($lat, $lon, $searchRadiusKm)
{
	$earthRadius = 6371; // km
	// If search radius is larger than the Earth's radius, 
	// we can't filter down at all here.
	if ( $searchRadiusKm >= $earthRadius * 0.99 )
	{
		$maxLat = 89.99;
		$minLat = -89.99;
		$maxLon = 179.99;
		$minLon = -179.99;
	}
	else
	{
		$r = $searchRadiusKm / $earthRadius;
		$latDelta = rad2deg($r);
		$maxLat = $lat + $latDelta;
		$minLat = $lat - $latDelta;
		if ( $maxLat >= 90 || $minLat <= -90 )
		{
			$maxLon = 179.99;
			$minLon = -179.99;
		}
		else
		{
			$latR = deg2rad($lat);
			$asinInput = sin($r) / cos($latR);
			if ( abs($asinInput) > 1 )
			{
				$maxLon = 179.99;
				$minLon = -179.99;
			}
			else
			{
				$lonDelta = rad2deg(asin( $asinInput ));
				$lon = BaseUser::getLongitude();
				$maxLon = $lon + $lonDelta;
				$minLon = $lon - $lonDelta;
			}
		}
	}

	return [
		'maxLat' => $maxLat,
		'minLat' => $minLat,
		'maxLon' => $maxLon,
		'minLon' => $minLon
	];
}

function getLatitudeAndLongitudeRangeFromBaseUser()
{
	$searchRadius = BaseUser::getSearchRadius(); // km
	$lat = BaseUser::getLatitude();
	$lon = BaseUser::getLongitude();
	return getLatitudeAndLongitudeRange($lat, $lon, $searchRadius);
}

/**
For efficiency's sake, we want to remove any locations that are outside 
the latitude and longitude range we're interested in.

The calculations are explained at:
http://janmatuschek.de/LatitudeLongitudeBoundingCoordinates
*/
function filterLatitudeAndLongitude($locationsQuery)
{
	$range = getLatitudeAndLongitudeRangeFromBaseUser();

	$locationsQuery = $locationsQuery->
		where('latitude', '<=', $range['maxLat'])->
		where('latitude', '>=', $range['minLat'])->
		where('longitude', '>=', $range['minLon'])->
		where('longitude', '<=', $range['maxLon']);

	return $locationsQuery;
}

function filterLocationsToMax($locations, $max)
{
	$currentLength = count($locations);
	if ( $currentLength > $max )
	{
		$searchRadius = BaseUser::getSearchRadius();
		$lat = BaseUser::getLatitude();
		$lon = BaseUser::getLongitude();
		$range = getLatitudeAndLongitudeRange($lat, $lon, $searchRadius);
		$buckets = [];
		$gridSize = 10;
		for ( $i = -$gridSize; $i < $gridSize; $i ++ )
		{
			$delta = ceil(sqrt($gridSize * $gridSize - $i * $i));
			for ( $j = -$delta; $j < $delta; $j ++ )
			{
				$buckets['_'.$i.'_'.$j] = [];
			}
		}
		$ratio = ( $gridSize * 1.0 ) / $searchRadius;
		foreach ($locations as $location)
		{
			$i = floor( ($location->longitude - $lon ) * $ratio );
			$j = floor( ($location->latitude - $lat ) * $ratio );
			$key = '_'.$i.'_'.$j;
			if ( isset( $buckets[$key] ) )
			{
				$buckets[$key] []= $location;
			}
		}
		$bucket_height_limit = calculateBucketHeightLimit($buckets, $max);
		$new_locations = [];
		foreach ($buckets as $key => $bucket)
		{ 
			$bucket = array_slice($bucket, 0, $bucket_height_limit);
			foreach ($bucket as $location)
			{
				$new_locations []= $location;
			}
		}
		$locations = $new_locations;
	}
	return [
		'locations' => $locations,
		'unlimited_location_count' => $currentLength
		];
}

function filterTooDistant($locations)
{
	// Remove locations that are too far away.
	$filtered_locations = [];
	$search_radius = BaseUser::getSearchRadius();
	foreach ($locations as $location) {
		if ( $location->distance <= $search_radius ) {
			$filtered_locations []= $location;
		}
	}
	return $filtered_locations;
}

function getSortedLocations($locationsQuery, $view, $order_by_field_name)
{
	// Order by id just to clarify that any filtering will be deterministic.
	$locationsQuery = $locationsQuery->orderBy('id');
	$locations = $locationsQuery->get();
	// get() doesn't return an array so let's make one.
	$loc_array = [];
	foreach ($locations as $loc)
	{
		$loc_array []= $loc;
	}
	$locations = $loc_array;
	updateDistances($locations);
	$locations = filterTooDistant($locations);

	$locationsResult = filterLocationsToMax($locations, 50);
	$locations = $locationsResult['locations'];
	
	if ( $view === 'table' ) {
		updateRatings($locations);
		if ( $order_by_field_name === 'name' )
			usort($locations, 'App\Http\Controllers\compareByName');
		else if ( $order_by_field_name === 'distance' )
			usort($locations, 'App\Http\Controllers\compareByDistance');
		else if ( $order_by_field_name === 'rating' )
			usort($locations, 'App\Http\Controllers\compareByRating');
	}
	return [
		'locations' => $locations,
		'unlimited_location_count' => $locationsResult['unlimited_location_count']
		];
}

/**
Used in location search views to get URL's for various tweaks to the state.
*/
class URLFactory
{
	public function __construct($params) {
		$this->params = $params;
	}

	private static function getURLFromParameters($params)
	{
		$url = '/location-search?';
		$needs_ampersand = false;
		foreach ($params as $param_name => $param_value) {
			if ( $needs_ampersand ) {
				$url .= '&amp;';
			}
			$url .= $param_name . '=' . rawurlencode($param_value);
			$needs_ampersand = true;
		}
		return $url;
	}

	private function cloneParams()
	{
		return array_flip(array_flip($this->params));
	}

	public function createURL()
	{
		return $this->params;
	}

	public function createURLForOrderByField($field_name)
	{
		$params = $this->cloneParams();
		$params['order_by'] = $field_name;
		return URLFactory::getURLFromParameters($params);
	}

	public function createURLForView($view)
	{
		$params = $this->cloneParams();
		$params['view'] = $view;
		return URLFactory::getURLFromParameters($params);
	}
}

class LocationSearchController extends Controller {

	/**
	search handles both search by location tag and by keywords.
	*/
	public function search(Request $request)
	{
		if ( Input::has('address') )
			BaseUser::setAddress(Input::get('address'));

		$locationsQuery = Location::query();
		$location_tag = '';
		$keywords = '';
		$location_tag_name = '';
		$location_tag_id = '';
		$order_by_field_name = 'name';
		if ( Input::has('order_by') )
		{
			$field_name = Input::get('order_by');
			if ( in_array($field_name, ['name', 'rating', 'distance']) )
			{
				$order_by_field_name = $field_name;
			}
		}
		$view = 'table';

		if ( Input::has('view') && ( Input::get('view') === 'map' || Input::get('view') === 'table' ) )
			$view = Input::get('view');

		if ( Input::has('location_tag_id') && is_numeric(Input::get('location_tag_id')) )
		{
			$location_tag_id = Input::get('location_tag_id');
			$location_tag = LocationTag::find($location_tag_id);
			$location_tag_name = $location_tag->name;
			$locationsQuery = $location_tag->locations();
		}
		if ( Input::has('keywords') || isset($_GET['keywords']) )
		{
			$keywords = Input::get('keywords');
			if ( !Input::has('keywords') )
			{
				$keywords = ' ';
			}
			$keywordsArray = explode(' ', $keywords);
			foreach ($keywordsArray as $keyword)
			{
				$locationsQuery->where('name', 'LIKE', '%' . $keyword . '%');
			}
			$locationsQuery = $locationsQuery->distinct();
		}
		else if ( !Input::has('location_tag_id') || !is_numeric(Input::get('location_tag_id')))
		{
			\App::abort(422, 'Either keywords or location_tag_id must be specified');
		}
		$locationsQuery = filterLatitudeAndLongitude($locationsQuery);		
		$locationsResult = getSortedLocations($locationsQuery, $view, $order_by_field_name);

		$url_factory = new URLFactory([
			'keywords' => $keywords,
			'order_by' => $order_by_field_name,
			'location_tag_id' => $location_tag_id,
			'view' => $view
		]);
		
		$search_radius = BaseUser::getSearchRadius();

		return view('pages.location_search.search',
			[
				'locations'          => $locationsResult['locations'],
				'max_reached'        => (count($locationsResult['locations']) < $locationsResult['unlimited_location_count']),
				'unlimited_location_count' => $locationsResult['unlimited_location_count'],
				'keywords'           => $keywords,
				'location_tag_name'  => $location_tag_name,
				'url_factory'        => $url_factory,
				'view'               => $view,
				'google_map_api_key' => config('app.google_map_api_key'),
				'turn_off_maps'      => config('app.turn_off_maps'),
				'order_by'           => $order_by_field_name,
				'search_radius'      => $search_radius
			]);
	}
	/**
	 * set search radius
	 */
	public function setSearchRadius(Request $request) {
		$distance = Input::get('distance');
		if(is_numeric($distance) ) {
			$int_distance = intval( $distance );
			BaseUser::setSearchRadius($int_distance);
		}
		return "hello";
	}
}
