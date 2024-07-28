<?php
/*
+--------------------------------------------------------------------+
| CiviCRM OSM Geocoding module (SYS-OSM)                             |
+--------------------------------------------------------------------+
| Copyright SYSTOPIA (c) 2014-2015                                   |
+--------------------------------------------------------------------+
| This is free software; you can copy, modify, and distribute it     |
| under the terms of the GNU Affero General Public License           |
| Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
|                                                                    |
| SYS-OSM is distributed in the hope that it will be useful, but     |
| WITHOUT ANY WARRANTY; without even the implied warranty of         |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
| See the GNU Affero General Public License for more details.        |
|                                                                    |
| You should have received a copy of the GNU Affero General Public   |
| License and the SYS-OSM Licensing Exception along                  |
| with this program; if not, contact SYSTOPIA                        |
| at info[AT]systopia[DOT]de. If you have questions about the        |
| GNU Affero General Public License or the licensing of CiviCRM,     |
| see the CiviCRM license FAQ at http://civicrm.org/licensing        |
+--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright SYSTOPIA (c) 2014-2015
 *
 */

/**
 * Class that uses OpenStreetMap (OSM) API to retrieve the lat/long of an address
 *
 * This CiviCRM extension requests geodata from nominatim.osm.org,
 * a service of OpenStreetMap Foundation. We have been advised that OSM servers
 * might not be suitable for massive data requests, but so far could not
 * get precise information on what this exactly means. Also, we don't know what
 * the consequences of requests too massive for the OSM infrastructure might be.
 * Therefore we recommend that you consider using the 'throtte' option for the
 * "Geocode and Parse Adresses" cronjob if processing data sets containing more
 * than 10.000 addresses.
 */
class CRM_Utils_Geocode_OpenStreetMapCoding {

  /**
   * OSM Nominatim server
   *
   * @var string
   * @static
   */
  protected static $_server = 'nominatim.openstreetmap.org';

  /**
   * uri of service
   *
   * @var string
   * @static
   */
  protected static $_uri = '/search';

  /**
   * function that takes an address array and gets the latitude / longitude
   * and postal code for this address. Note that at a later stage, we could
   * make this function also clean up the address into a more valid format
   *
   * @param array $values associative array of address data: country, street_address, city, state_province, postal code
   * @param boolean $stateName this params currently has no function
   *
   * @return boolean true if we modified the address, false otherwise
   * @static
   */
  public static function format(&$values, $stateName = FALSE) {
    CRM_Utils_System::checkPHPVersion(5, TRUE);

    $params = [];

    // TODO: is there a more failsafe format for street and street-number?
    if (CRM_Utils_Array::value('street_address', $values) && !empty($values['street_address'])) {
      $params['street'] = $values['street_address'];
    }

    if (CRM_Utils_Array::value('city', $values) && !empty($values['city'])) {
      $params['city'] = $values['city'];
    }

    if (CRM_Utils_Array::value('state_province', $values) && !empty($values['state_province'])) {
      if (CRM_Utils_Array::value('state_province_id', $values)) {
        $stateProvince = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_StateProvince', $values['state_province_id']);
      }
      else {
        if (!$stateName) {
          $stateProvince = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_StateProvince',
            $values['state_province'],
            'name',
            'abbreviation'
          );
        }
        else {
          $stateProvince = $values['state_province'];
        }
      }

      // TODO: do we need this? This originated from CRM-2632 / Google geocoder
      if ($stateProvince != $city) {
        $params['state'] = $stateProvince;
      }
    }

    if (CRM_Utils_Array::value('postal_code', $values) && !empty($values['postal_code'])) {
      $params['postalcode'] = $values['postal_code'];
    }

    if (CRM_Utils_Array::value('country', $values) && !empty($values['country'])) {
      $params['country'] = $values['country'];
    }

    if (count($params) === 0) {
      $values['geo_code_1'] = $values['geo_code_2'] = 'null';

      return FALSE;
    }

    $params['addressdetails'] = '1';
    $url = "https://" . self::$_server . self::$_uri . '?format=json';

    $coord = self::makeRequest($url, $params);

    if (count($coord) === 0) {
      //try again without street. It often fails, because of wrong spelling
      unset($params['street']);
      $coord = self::makeRequest($url, $params);
    }

    $values['geo_code_1'] = $coord['geo_code_1'] ?? 'null';
    $values['geo_code_2'] = $coord['geo_code_2'] ?? 'null';

    if (!array_key_exists('state_province_id', $values) || $values['state_province_id'] === 'null' || $values['state_province_id'] === '') {
      $values['state_province_id'] = $coord['state_province_id'] ?? 'null';
    }
    if (!array_key_exists('county_id', $values) || $values['county_id'] === 'null' || $values['county_id'] === '') {
      $values['county_id'] = $coord['county_id'] ?? 'null';
    }
    if (!array_key_exists('country_id', $values) || $values['country_id'] === 'null' || $values['country_id'] === '') {
      $values['country_id'] = $coord['country_id'] ?? 'null';
    }
  
    if (isset($coord['geo_code_error'])) {
      $values['geo_code_error'] = $coord['geo_code_error'];
    }

    return isset($coord['geo_code_1'], $coord['geo_code_2']);
  }

  public static function getCoordinates($address): array {
    return self::makeRequest(urlencode($address));
  }

  /**
   * @param string $url
   *   Url-encoded address
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private static function makeRequest($url, $params): array {
    // Nominatim requires that we cache lookups, since they're donating this
    // service for free.

    $urlWithParams = $url;
    foreach ($params as $key => $value) {
      $urlWithParams .= '&' . urlencode($key) . '=' . urlencode($value);
    }

    $cache = CRM_Utils_Cache::create(['type' => ['SqlGroup'], 'name' => 'geocode_osm']);
    $cacheKey = substr(sha1($urlWithParams), 0, 12);
    $json = $cache->get($cacheKey);
    $foundInCache = !empty($json);

    if (!$foundInCache) {
      // No valid value found in cache.
      $client = new GuzzleHttp\Client();
      // Nominatim's terms of use require us to submit a real user agent to
      // identify ourselves.  Rate limiting may be done using this. We use the
      // configured API key if set, otherwise we use a unique hash.  We use the
      // unique has instead of the domain name since sending the addresses of
      // everybody to do with the organisation along with an identifier for the
      // organisation could be sensitive.
      // @see https://operations.osmfoundation.org/policies/nominatim/
      $appName =  CRM_Core_Config::singleton()->geoAPIKey ?: substr(sha1(CRM_Core_BAO_Domain::getDomain()->name . CIVICRM_SITE_KEY), 0, 12);
      $request = $client->request('GET', $urlWithParams, ['headers' => ['User-Agent' => "CiviCRM instance ($appName)"]]);

      // check if request was successful
      if ($request->getStatusCode() != 200) {
        CRM_Core_Error::debug_log_message('Geocoding failed, invalid response code ' . $request->getStatusCode());
        return ['geo_code_error' => 'Geocoding failed, invalid response code ' . $request->getStatusCode()];
        if ($request->getStatusCode() == 429) {
          // provider says 'TOO MANY REQUESTS'
          return ['geo_code_error' => 'OVER_QUERY_LIMIT'];
        }
        else {
          return ['geo_code_error' => $request->getStatusCode()];
        }
      }

      // Process results
      $string = $request->getBody();
      $json = json_decode($string, TRUE);
    }

    if (is_null($json) || !is_array($json)) {
      // $string could not be decoded; maybe the service is down...
      // We don't save this in the cache.
      CRM_Core_Error::debug_log_message('Geocoding failed. "' . $string . '" is no valid json-code. (' . $urlWithParams . ')');
      return ['geo_code_error' => 'Geocoding failed. "' . $string . '" is no valid json-code. (' . $urlWithParams . ')'];

    }
    elseif (count($json) == 0) {
      // Array is empty; address is probably invalid...
      // Error logging is disabled, because it potentially reveals address data to the log
      // CRM_Core_Error::debug_log_message('Geocoding failed.  No results for: ' . $urlWithParams);
      // Save in cache so we don't keep repeating the same failed query.
      $cache->set($cacheKey, $json);
      return [];
    }
    elseif (is_array($json[0]) && array_key_exists('lat', $json[0]) && array_key_exists('lon', $json[0])) {
      // TODO: Process other relevant data to update address
      // Save in cache.
      $cache->set($cacheKey, $json);

      [$country_id, $state_province_id, $county_id] = self::getCountryCountyStateID($json[0]['address'], $params);

      return [
        'geo_code_1' => (float) substr($json[0]['lat'], 0, 12),
        'geo_code_2' => (float) substr($json[0]['lon'], 0, 12),
        'country_id' => $country_id,
        'state_province_id' => $state_province_id,
        'county_id' => $county_id,
      ];

    }
    else {
      // Don't know what went wrong... we got an array, but without lat and lon.
      // We don't save this in the cache.
      \Civi::log()->info('Geocoding failed. Response was positive, but no coordinates were delivered.', [
        'url' => $urlWithParams,
      ]);
      return [];
    }
  }

  /**
   * @param object $address
   *   address from geocoding result
   * @return array
   *   ID of state and conty
   */
  public static function getCountryCountyStateID($address, $params = []) {
    $county_id = NULL;
    $state_province_id = NULL;
    $country_id = NULL;

    debug_to_console($params);

    if (!array_key_exists('country', $params)) {
      //use iso_code, because names are translated
      if (array_key_exists('country_code', $address)) {
        $country_code = $address['country_code'];
      }

      if (!empty($country_code)) {
        $countries = \Civi\Api4\Country::get(FALSE)
          ->addWhere('iso_code', '=', $country_code)
          ->execute();
        if ($countries->count() > 0) {
          $country_id = $countries->first()['id'];
        }
      }

      if (!isset($country_id)) {
        return [$country_id, $state_province_id, $county_id];
      }
    }

    if (!array_key_exists('state_province', $params)) {
      if (array_key_exists('state', $address)) {
        $stateName = $address['state']; //Bundesland
      }
      elseif (!empty($countyName)) {
        $stateName = $countyName; //e.g. Hamburg
      }

      if (!empty($stateName)) {
        $state = \Civi\Api4\StateProvince::get(FALSE)
          ->addWhere('name', '=', $stateName)
          ->execute();
        if ($state->count() > 0) {
          $state_province_id = $state->first()['id'];
        }
      }

      if (!isset($state_province_id)) {
        return [$country_id, $state_province_id, $county_id];
      }
    }

    if (!array_key_exists('county', $params)) {
      if (array_key_exists('county', $address)) {
        $countyName = $address['county'];//Landkreis
      }
      elseif (array_key_exists('city', $address)) {
        $countyName = $address['city'];//Kreisfreiestadt
      }
      elseif (array_key_exists('town', $address)) {
        $countyName = $address['town'];//Kreisfreiestadt with alternative name, city vs.
      }

      if (!empty($countyName)) {
        $counties = \Civi\Api4\County::get(FALSE)
          ->addWhere('name', '=', $countyName)
          ->execute();
        if ($counties->count() > 0) {
          $county_id = $counties->first()['id'];
        }
        else if (isset($state_province_id)) {
          // create the county because civicrm db for counties is normal empty
          $county = \Civi\Api4\County::create(FALSE)
            ->addValue('state_province_id', $state_province_id)
            ->addValue('name', $countyName)
            ->execute();
          if ($county->count() > 0) {
            $county_id = $county->first()['id'];
          }
        }
      }
    }

    return [$country_id, $state_province_id, $county_id];
  }

}
