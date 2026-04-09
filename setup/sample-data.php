<?php
/**
 * Import Maho sample data (products, categories, media, config)
 *
 * Downloads the official maho-sample-data for the current Maho version,
 * remaps attribute IDs, converts SQL for SQLite, and imports everything.
 *
 * Requires: Maho already installed (local.xml + tables exist)
 * Usage: php sample-data.php
 */
chdir("/app");
require "vendor/autoload.php";

Mage::register("isSecureArea", true, true);
Mage::app("admin");

$mahoVersion = Mage::getVersion();
$parts = explode(".", $mahoVersion);
$branch = $parts[0] . "." . $parts[1];
echo "Maho $mahoVersion — downloading sample data from branch $branch...\n";

$url = "https://github.com/MahoCommerce/maho-sample-data/archive/refs/heads/{$branch}.tar.gz";
$tmpFile = tempnam(sys_get_temp_dir(), "maho_sd_");
$baseDir = Mage::getBaseDir();

if (file_put_contents($tmpFile, file_get_contents($url)) === false) {
    die("ERROR: Failed to download sample data\n");
}

echo "Extracting...\n";
exec("tar -xzf $tmpFile -C $baseDir", $out, $ret);
if ($ret !== 0) {
    die("ERROR: tar failed\n");
}

$sdDir = "$baseDir/maho-sample-data-$branch";

// Copy media
echo "Copying media files...\n";
exec("cp -R $sdDir/media/* $baseDir/public/media/ 2>/dev/null");

// Determine SQLite path from local.xml
$dbName = (string)Mage::getConfig()->getNode('global/resources/default_setup/connection/dbname');
echo "Database: $dbName\n";

// Import SQL
echo "Importing database...\n";
$pdo = new PDO("sqlite:$dbName");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$logCallback = function (string $msg, string $level = "info") {
    echo "  [$level] $msg\n";
};

$dataSql = file_get_contents("$sdDir/db_data.sql");
$importer = new MahoCLI\Helper\SampleDataImporter($pdo, $logCallback);

echo "Parsing attribute mappings...\n";
$remappedSql = $importer->import($dataSql);
$remap = $importer->getAttributeRemap();
echo "Remapped " . count($remap) . " attributes\n";

echo "Executing SQL...\n";
$converter = new MahoCLI\Helper\SqlConverter();
$converter->setPdo($pdo);
$convertedSql = $converter->mysqlToSqlite($remappedSql);
$converter->executeStatements($pdo, $convertedSql, function ($cur, $total) {
    if ($cur === $total || $cur % 500 === 0) {
        echo "\r  Progress: $cur/$total...";
    }
});
echo "\n";

$importer->mergeAttributeGroups();
$importer->mergeEntityAttributes();

// Config SQL
$configFile = "$sdDir/db_config.sql";
if (file_exists($configFile)) {
    echo "Importing config...\n";
    $configSql = file_get_contents($configFile);
    $remappedConfig = $importer->remapConfigValuesOnly($configSql);
    $convertedConfig = $converter->mysqlToSqlite($remappedConfig);
    $converter->executeStatements($pdo, $convertedConfig);
    echo "Config imported\n";
}

// Cleanup
unlink($tmpFile);
exec("rm -rf " . escapeshellarg($sdDir));

echo "\nSample data installed successfully!\n";
echo "Run: php /app/maho index:reindex:all && php /app/maho cache:flush\n";
