-- Executed by the postgres image on first start only (when the data volume is empty),
-- as the POSTGRES_USER defined in docker-compose.db.yml.
--
-- The automated test suite uses its own database so RefreshDatabase never touches
-- development data. The name must match DB_DATABASE in phpunit.xml.
CREATE DATABASE askmydocs_test;
