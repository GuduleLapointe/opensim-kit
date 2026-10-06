<?php

declare(strict_types=1);

/**
 * What the OpenSimSearch module sends to the web service and reads from its answers, from its source
 * (modules/OpenSimSearch), against the contract the search tests of the packages check the helpers with
 * (tests/Packaging/search/contract.php): when the module changes, this test says what to update.
 */

/**
 * The calls of the module to the web service: for each place of its source that calls a method of the service, the keys
 * it sends (before the call) and the ones it reads in the answer (after it, up to the next call).
 *
 * @return array<string, array{request: string[], response: string[]}> by "Method/service_method"
 */
function search_module_calls(string $source): array
{
    $calls = [];
    // The members of the class, one after the other
    $members = preg_split('/\n {8}(?=(?:public|protected|private)\s+[\w<>\[\]]+\s+(\w+)\()/', $source) ?: [];
    foreach ($members as $member) {
        if (!preg_match('/^(?:public|protected|private)\s+[\w<>\[\]]+\s+(\w+)\(/', $member, $name)) {
            continue;
        }
        preg_match_all('/GenericXMLRPCRequest\(\s*ReqHash,\s*"(\w+)"\s*\)/', $member, $found, PREG_OFFSET_CAPTURE);
        $start = 0;
        foreach ($found[1] as $i => [$method, $at]) {
            $next = $found[0][$i + 1][1] ?? strlen($member);
            preg_match_all('/ReqHash\["(\w+)"\]\s*=/', substr($member, $start, $at - $start), $sent);
            preg_match_all('/\bd\["(\w+)"\]/', substr($member, $at, $next - $at), $read);
            $request = array_values(array_unique($sent[1]));
            $response = array_values(array_unique($read[1]));
            sort($request);
            sort($response);
            $calls["{$name[1]}/$method"] = ['request' => $request, 'response' => $response];
            $start = $at;
        }
    }
    ksort($calls);

    return $calls;
}

describe('Search module contract', function () {
    $source = dirname(__DIR__, 2) . '/modules/OpenSimSearch/OpenSimSearch/Modules/SearchModule/OpenSearch.cs';

    test('is read from the source of the module', function () use ($source) {
        expect($source)->toBeFile();
        expect(array_keys(search_module_calls((string) file_get_contents($source))))->toContain(
            'DirPlacesQuery/dir_places_query',
            'ClassifiedInfoRequest/classifieds_info_query',
        );
    });

    test('is what the search tests of the packages check the helpers with', function () use ($source) {
        $contract = require dirname(__DIR__) . '/Packaging/search/contract.php';

        expect($contract)->toBe(search_module_calls((string) file_get_contents($source)));
    });
});
