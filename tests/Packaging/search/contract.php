<?php

declare(strict_types=1);

/**
 * What the OpenSimSearch module sends to the web service (query.php of the helpers) and reads in its answers, for each
 * call it makes: the keys of the request, and the keys of the answer it needs. Checked against the source of the module by
 * tests/Unit/SearchModuleContractTest.php, used by the search tests of the packages (tests/Packaging/search.sh).
 */
return [
    'ClassifiedInfoRequest/classifieds_info_query' => [
        'request' => ['classifiedID'],
        'response' => ['category', 'classifiedflags', 'classifieduuid', 'creationdate', 'creatoruuid', 'description', 'expirationdate', 'name', 'parcelname', 'parceluuid', 'parentestate', 'posglobal', 'priceforlisting', 'simname', 'snapshotuuid'],
    ],
    'DirClassifiedQuery/dir_classified_query' => [
        'request' => ['category', 'flags', 'query_start', 'text'],
        'response' => ['classifiedflags', 'classifiedid', 'creation_date', 'expiration_date', 'name', 'priceforlisting'],
    ],
    'DirEventsQuery/dir_events_query' => [
        'request' => ['flags', 'query_start', 'text'],
        'response' => ['date', 'event_flags', 'event_id', 'name', 'owner_id', 'unix_time'],
    ],
    'DirLandQuery/dir_land_query' => [
        'request' => ['area', 'flags', 'price', 'query_start', 'type'],
        'response' => ['area', 'auction', 'for_sale', 'name', 'parcel_id', 'sale_price'],
    ],
    'DirPlacesQuery/dir_places_query' => [
        'request' => ['category', 'flags', 'query_start', 'sim_name', 'text'],
        'response' => ['auction', 'dwell', 'for_sale', 'name', 'parcel_id'],
    ],
    'DirPopularQuery/dir_popular_query' => [
        'request' => ['flags'],
        'response' => ['dwell', 'name', 'parcel_id'],
    ],
    'EventInfoRequest/event_info_query' => [
        'request' => ['eventID'],
        'response' => ['category', 'coveramount', 'covercharge', 'creator', 'date', 'dateUTC', 'description', 'duration', 'event_id', 'eventflags', 'globalposition', 'name', 'simname'],
    ],
    'HandleMapItemRequest/dir_events_query' => [
        'request' => ['flags', 'query_start', 'text'],
        'response' => ['event_id', 'landing_point', 'name', 'unix_time'],
    ],
    'HandleMapItemRequest/dir_land_query' => [
        'request' => ['area', 'flags', 'price', 'query_start', 'type'],
        'response' => ['area', 'landing_point', 'name', 'parcel_id', 'region_UUID', 'sale_price'],
    ],
];
