-- Testdata: kjøres etter schema.sql (alfabetisk rekkefølge i entrypoint.sh)
-- Viser at web-containeren faktisk leser fra databasen ved første test.
INSERT INTO messages (author, body) VALUES ('Docker', 'Denne meldingen kommer fra PostgreSQL-containeren.');
