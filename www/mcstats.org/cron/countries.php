<?php

define('ROOT', '../public_html/');
define('MAX_CHILDREN', 30);

require_once ROOT . 'config.php';
require_once ROOT . 'includes/database.php';
require_once ROOT . 'includes/func.php';

// we want the data for the last hour
$minimum = strtotime('-30 minutes', $baseEpoch);

// the current number of running forks
$running_processes = 0;

// Load all of the countries we can use
$countries = loadCountries();
$baseEpoch = normalizeTime();
$minimum = strtotime('-30 minutes', $baseEpoch);

// iterate through all of the plugins
foreach (loadPlugins(PLUGIN_ORDER_ALPHABETICAL) as $plugin)
{
    // are we at the process limit ?
    if ($running_processes >= MAX_CHILDREN)
    {
        // wait for some children to be allocated
        pcntl_wait($status);
        $running_processes --;
    }

    $running_processes ++;
    $pid = pcntl_fork();

    if ($pid == 0)
    {
        $master_db_handle = try_connect_database();

        foreach ($countries as $shortCode => $fullName)
        {
            $servers = 0;

            // load the players online in the last hour
            if ($plugin->getID() != GLOBAL_PLUGIN_ID)
            {
                $statement = $db_handle->prepare('
                    SELECT
                        SUM(1) AS Sum,
                        COUNT(dev.Server) AS Count,
                        AVG(1) AS Avg,
                        MAX(1) AS Max,
                        MIN(1) AS Min,
                        VAR_SAMP(1) AS Variance,
                        STDDEV_SAMP(1) AS StdDev
                    FROM (SELECT DISTINCT Server, Server.Players from ServerPlugin LEFT OUTER JOIN Server ON Server.ID = ServerPlugin.Server WHERE Country = ? AND ServerPlugin.Plugin = ? AND ServerPlugin.Updated >= ?) dev');
                $statement->execute(array($shortCode, $this->id, $minimum));
            } else
            {
                $statement = $db_handle->prepare('
                    SELECT
                        SUM(1) AS Sum,
                        COUNT(dev.Server) AS Count,
                        AVG(1) AS Avg,
                        MAX(1) AS Max,
                        MIN(1) AS Min,
                        VAR_SAMP(1) AS Variance,
                        STDDEV_SAMP(1) AS StdDev
                    FROM (SELECT DISTINCT Server, Server.Players from ServerPlugin LEFT OUTER JOIN Server ON Server.ID = ServerPlugin.Server WHERE Country = ? AND ServerPlugin.Updated >= ?) dev');
                $statement->execute(array($shortCode, $minimum));

                if ($row = $statement->fetch())
                {
                    $servers = $row['Count'];
                }
            }

            $data = $statement->fetch();
            $sum = $data['Sum'];
            $count = $data['Count'];
            $avg = $data['Avg'];
            $max = $data['Max'];
            $min = $data['Min'];
            $variance = $data['Variance'];
            $stddev = $data['StdDev'];

            if ($count == 0)
            {
                continue;
            }

            $graph = $plugin->getOrCreateGraph('Version Trends');
            $columnID = $graph->getColumnID($version);

            // insert it into the database
            $statement = $master_db_handle->prepare('INSERT INTO CustomDataTimeline (Plugin, ColumnID, Sum, Count, Avg, Max, Min, Variance, StdDev, Epoch)
                                                    VALUES (:Plugin, :ColumnID, :Sum, :Count, :Avg, :Max, :Min, :Variance, :StdDev, :Epoch)');
            $statement->execute(array(
                ':Plugin' => $plugin->getID(),
                ':ColumnID' => $columnID,
                ':Epoch' => $baseEpoch,
                ':Sum' => $sum,
                ':Count' => $count,
                ':Avg' => $avg,
                ':Max' => $max,
                ':Min' => $min,
                ':Variance' => $variance,
                ':StdDev' => $stddev
            ));
        }

        exit(0);
    }
}

// wait for all of the processes to finish
while ($running_processes > 0)
{
    pcntl_wait($status);
    $running_processes --;
}