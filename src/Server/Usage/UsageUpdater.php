<?php

namespace Scp\WhmcsReseller\Server\Usage;

use Scp\Api\ApiError;
use Scp\Server\Server;
use Scp\Server\ServerRepository;
use Scp\WhmcsReseller\Api;
use Scp\WhmcsReseller\Database\Database;
use Scp\WhmcsReseller\LogFactory;

class UsageUpdater
{
    /**
     * @var LogFactory
     */
    protected $log;

    /**
     * @var UsageFormatter
     */
    protected $format;

    /**
     * @var ServerRepository
     */
    protected $servers;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Api
     */
    protected $api;

    public function __construct(
        Api $api,
        Database $database,
        LogFactory $log,
        UsageFormatter $format,
        ServerRepository $servers
    ) {
        $this->api = $api;
        $this->log = $log;
        $this->format = $format;
        $this->servers = $servers;
        $this->database = $database;
    }

    /**
     * @return bool
     */
    public function runAndLogErrors()
    {
        try {
            $this->run();

            return true;
        } catch (ApiError $exc) {
            $this->log->activity(
                'SynergyCP: Error running usage update: %s',
                $exc->getMessage()
            );
        }

        return false;
    }

    /**
     * @throws ApiError
     *
     * @return bool
     */
    public function run()
    {
        // Get bandwidth from SynergyCP
        $fail = false;
        Server::query()->where('integration_id', 'me')->chunk(100, function ($servers) use (&$fail) {
            $servers->map(function (Server $server) use (&$fail) {
                try {
                    $this->database
                        ->table('tblhosting')
                        ->where('id', $server->billing->id)
                        ->update($this->prepareUpdates($server));
                } catch (\Exception $exc) {
                    $this->log->activity(
                        'SynergyCP: Usage Update failed: %s',
                        $exc->getMessage()
                    );
                    $fail = true;
                }
            });
        });

        $this->log->activity('SynergyCP: Completed usage update');

        return !$fail;
    }

    /**
     * @param Server $server
     *
     * @return array
     */
    private function prepareUpdates(Server $server)
    {
        $usage = $server->usage;

        return [
            'bwusage'    => $usage ? $this->format->bitsToMB($usage->used, 3) : 0,
            'bwlimit'    => $usage ? $this->format->bitsToMB($usage->max, 3) : 0,
            'lastupdate' => 'now()',
        ];
    }
}
