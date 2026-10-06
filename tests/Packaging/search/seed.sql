-- What the simulators do not bring to the search tables of the test: events (made by 2do or another calendar),
-- classifieds (made by the viewers, kept by OpenSimulator) and enough parcels to need a second page.
-- {{regions}} is the name of the region table of the search.

-- Events: a concert and an exhibition coming, an adult party coming, a meeting five days ago (at noon, UTC)
INSERT INTO events (owneruuid, name, creatoruuid, category, description, dateUTC, duration, covercharge, coveramount,
    simname, parcelUUID, globalPos, eventflags, mature) VALUES
  ('aaaaaaaa-0000-4000-8000-000000000001', 'Live Jazz Night', 'aaaaaaaa-0000-4000-8000-000000000001', 20,
    'Jazz quartet, live', UNIX_TIMESTAMP() + 7200, 120, 1, 50, 'Alpha', '10000000-0000-4000-8000-000000000011',
    '2048512/2048512/30', 0, 'false'),
  ('aaaaaaaa-0000-4000-8000-000000000001', 'Art Opening', 'aaaaaaaa-0000-4000-8000-000000000001', 27,
    'Opening of the spring exhibition', UNIX_TIMESTAMP() + 93600, 180, 0, 0, 'Beta', '50000000-0000-4000-8000-000000000055',
    '2048768/2048512/25', 1, 'true'),
  ('aaaaaaaa-0000-4000-8000-000000000001', 'Adult Party', 'aaaaaaaa-0000-4000-8000-000000000001', 23,
    'After hours', UNIX_TIMESTAMP() + 10800, 240, 1, 100, 'Gamma', '60000000-0000-4000-8000-000000000066',
    '2049024/2048512/25', 2, 'true'),
  ('aaaaaaaa-0000-4000-8000-000000000001', 'Past Meeting', 'aaaaaaaa-0000-4000-8000-000000000001', 18,
    'A meeting that is over', (FLOOR(UNIX_TIMESTAMP() / 86400) - 5) * 86400 + 43200, 60, 0, 0, 'Alpha',
    '10000000-0000-4000-8000-000000000011', '2048512/2048512/30', 0, 'false');

-- Classifieds: two for everybody, one for adults
INSERT INTO classifieds (classifieduuid, creatoruuid, creationdate, expirationdate, category, name, description,
    parceluuid, parentestate, snapshotuuid, simname, posglobal, parcelname, classifiedflags, priceforlisting) VALUES
  ('eeeeeeee-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000001', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + 604800,
    '7', 'Furnished Loft', 'A loft by the garden, furnished', '10000000-0000-4000-8000-000000000011', 101,
    'cccccccc-0000-4000-8000-000000000001', 'Alpha', '<128,128,25>', 'Garden of Alpha', 4, 50),
  ('eeeeeeee-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-000000000001', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + 604800,
    '4', 'Night Companion', 'For adults', '50000000-0000-4000-8000-000000000055', 101,
    '00000000-0000-0000-0000-000000000000', 'Beta', '<100,100,25>', 'Mature Lounge', 64, 200),
  ('eeeeeeee-0000-4000-8000-000000000003', 'aaaaaaaa-0000-4000-8000-000000000001', UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + 604800,
    '3', 'Beach Plot Rental', 'A plot by the beach', '10000000-0000-4000-8000-000000000011', 101,
    '00000000-0000-0000-0000-000000000000', 'Alpha', '<64,64,22>', 'Sale Meadow', 4, 10);

-- A region with 120 parcels shown in search, which the searches by name have to give by pages of a hundred
INSERT INTO `{{regions}}` (regionname, regionUUID, regionhandle, url, owner, ownerUUID, gatekeeperURL)
  VALUES ('Delta', '44444444-4444-4444-8444-444444444444', '1', 'http://127.0.0.1:9992/', 'Alice Owner',
    'aaaaaaaa-0000-4000-8000-000000000001', '');
INSERT INTO parcels (parcelUUID, regionUUID, parcelname, landingpoint, description, searchcategory, build, script, public,
    dwell, infouuid, mature, gatekeeperURL, imageUUID)
  SELECT UUID(), '44444444-4444-4444-8444-444444444444', CONCAT('Paging Plot ', LPAD(seq, 3, '0')), '1/1/1', 'a plot',
    '0', 'true', 'true', 'true', 0, UUID(), 'PG', NULL, NULL FROM seq_1_to_120;
