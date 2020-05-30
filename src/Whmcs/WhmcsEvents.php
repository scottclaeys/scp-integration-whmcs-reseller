<?php

namespace Scp\WhmcsReseller\Whmcs;

use Scp\Server;
use Scp\WhmcsReseller\Client\ClientService;
use Scp\WhmcsReseller\Database\Database;
use Scp\WhmcsReseller\LogFactory;
use Scp\WhmcsReseller\Server\ServerService;
use Scp\WhmcsReseller\Server\Usage\UsageUpdater;
use Scp\WhmcsReseller\Ticket\TicketCreationFailed;
use Scp\WhmcsReseller\Ticket\TicketManager;

/**
 * Class Responsibilities:
 *  - Respond to internal WHMCS events by routing them into proper handlers.
 */
class WhmcsEvents
{
    // The internal WHMCS names of events.
    // TODO: move to interface

    /**
     * @var string
     */
    const PROVISION = 'CreateAccount';

    /**
     * @var string
     */
    const TERMINATE = 'TerminateAccount';

    /**
     * @var string
     */
    const SUSPEND = 'SuspendAccount';

    /**
     * @var string
     */
    const UNSUSPEND = 'UnsuspendAccount';

    /**
     * @var string
     */
    const USAGE = 'UsageUpdate';

    /**
     * @var string
     */
    const SUCCESS = 'success';

    /**
     * @var LogFactory`
     */
    protected $log;

    /**
     * @var UsageUpdater
     */
    protected $usage;

    /**
     * @var ServerService
     */
    protected $server;

    /**
     * @var WhmcsConfig
     */
    protected $config;

    /**
     * @var TicketManager
     */
    protected $ticket;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var ClientService
     */
    protected $clients;

    /**
     * @var WhmcsButtons
     */
    protected $buttons;

    /**
     * @param Database      $database
     * @param LogFactory    $log
     * @param WhmcsConfig   $config
     * @param UsageUpdater  $usage
     * @param ClientService $clients
     * @param ServerService $server
     * @param TicketManager $ticket
     * @param WhmcsButtons  $buttons
     */
    public function __construct(
        Database $database,
        LogFactory $log,
        WhmcsConfig $config,
        UsageUpdater $usage,
        ClientService $clients,
        ServerService $server,
        TicketManager $ticket,
        WhmcsButtons $buttons
    ) {
        $this->log = $log;
        $this->usage = $usage;
        $this->config = $config;
        $this->server = $server;
        $this->ticket = $ticket;
        $this->clients = $clients;
        $this->database = $database;
        $this->buttons = $buttons;
    }

    /**
     * @return array
     */
    public static function functions()
    {
        return [
            static::PROVISION => 'provision',
            static::USAGE     => 'usage',
            static::TERMINATE => 'terminate',
            static::SUSPEND   => 'suspend',
            static::UNSUSPEND => 'unsuspend',
        ];
    }

    /**
     * Triggered on Server Provisioning.
     *
     * @return string
     */
    public function provision()
    {
        try {
            $client = $this->clients->getOrCreate();
            $server = $this->server->currentOrFail();

            $server->grantAccess($client);

            $this->buttons->fillData();
        } catch (\Exception $exc) {
            return $exc->getMessage();
        }

        return static::SUCCESS;
    }

    /**
     * Run the usage update function.
     *
     * @return string
     */
    public function usage()
    {
        return $this->usage->runAndLogErrors()
            ? static::SUCCESS
            : 'Error running usage update';
    }

    /**
     * Terminate an account, logging and returning any errors that occur.
     *
     * @return string
     */
    public function terminate()
    {
        try {
            // TODO
            return 'Terminating servers is not yet available in this package.';
//            return $this->doDeleteAction();
        } catch (\Exception $exc) {
            $this->logException($exc, __FUNCTION__);

            return $exc->getMessage();
        }

        return static::SUCCESS;
    }

    /**
     * @param \Exception $exc
     * @param string     $action
     */
    private function logException(\Exception $exc, $action)
    {
        $this->log->activity(
            'SynergyCP: error during %s: %s',
            $action,
            $exc->getMessage()
        );
    }

    /**
     * Triggered on a Suspension event.
     *
     * @return string
     */
    public function suspend()
    {
        try {
            $server = $this->server->currentOrFail();

            try {
                // TODO: differentiate between auto and regular suspend.
                // TODO: get suspension reason
                $server->autoSuspendSubClients('See WHMCS');
                $this->createSuspensionTicket();

                return static::SUCCESS;
            } catch (Server\Exceptions\AutoSuspendIgnored $exc) {
                $this->createVipSuspensionTicket($server);
            }
        } catch (\Exception $exc) {
            $this->logException($exc, __FUNCTION__);

            return $exc->getMessage();
        }

        return static::SUCCESS;
    }

    /**
     * Run the create cancellation ticket delete action.
     *
     * @throws TicketCreationFailed
     */
    protected function createSuspensionTicket()
    {
        $message = sprintf(
            'Server with billing ID %d has been suspended.',
            $this->server->currentBillingId()
        );

        $this->ticket->create([
            'clientid' => $this->config->get('userid'),
            'subject'  => 'Server Suspension',
            'message'  => $message,
        ]);
    }

    /**
     * Run the create cancellation ticket delete action.
     *
     * @throws TicketCreationFailed
     */
    protected function createVipSuspensionTicket()
    {
        $message = sprintf(
            'This is a notice that the server with billing ID %d is pending suspension. We will not suspend any services on your account automatically, so this ticket will be manually reviewed before processing.',
            $this->server->currentBillingId()
        );

        $this->ticket->create([
            'clientid' => $this->config->get('userid'),
            'subject'  => 'Pending Server Suspension',
            'message'  => $message,
        ]);
    }

    /**
     * Triggered on an Unsuspension event.
     *
     * @return string
     */
    public function unsuspend()
    {
        try {
            $this->server
                 ->currentOrFail()
                 ->unsuspendSubClients();

            // return static::SUCCESS;
        } catch (\Exception $exc) {
            $this->logException($exc, __FUNCTION__);

            return $exc->getMessage();
        }

        return static::SUCCESS;
    }

    /**
     * Delete the current server using the action chosen in settings.
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function doDeleteAction()
    {
        try {
            switch ($act = $this->config->option(WhmcsConfig::DELETE_ACTION)) {
                case WhmcsConfig::DELETE_ACTION_WIPE:
                    $server = $this->server->currentOrFail();

                    try {
                        // TODO: differentiate between auto and regular suspend.
                        $server->autoWipe();
                        $this->wipeProductDetails();

                        return static::SUCCESS;
                    } catch (Server\Exceptions\AutoWipeIgnored $exc) {
                        $this->createVipTerminationTicket();

                        return static::SUCCESS;
                    }
                    break;
                case WhmcsConfig::DELETE_ACTION_TICKET:
                    $this->createCancellationTicket();
                    break;
                default:
                    $msg = sprintf(
                        'Unhandled delete action: %s',
                        $act
                    );

                    throw new \RuntimeException($msg);
            }
        } catch (\Exception $exc) {
            $this->logException($exc, __FUNCTION__);

            return $exc->getMessage();
        }

        return static::SUCCESS;
    }

    /**
     * Remove the current service's product details from the database.
     */
    protected function wipeProductDetails()
    {
        $serviceId = $this->config->get('serviceid');
        $updated = $this->database
            ->table('tblhosting')
            ->where('id', $serviceId)
            ->update([
                'domain'      => '',
                'dedicatedip' => '',
                'assignedips' => '',
            ]);

        $this->log->activity(
            '%s service ID: %s during termination',
            $updated ? 'Successfully updated' : 'Failed to update',
            $serviceId
        );
    }

    /**
     * Run the create cancellation ticket delete action.
     *
     * @throws TicketCreationFailed
     */
    protected function createVipTerminationTicket()
    {
        $message = sprintf(
            'This is a notice that the server with billing ID %d is pending termination. We will not terminate any services on your account automatically, so this ticket will be manually reviewed before processing.',
            $this->server->currentBillingId()
        );

        $this->ticket->create([
            'clientid' => $this->config->get('userid'),
            'subject'  => 'Pending Server Termination',
            'message'  => $message,
        ]);
    }

    /**
     * Run the create cancellation ticket delete action.
     *
     * @throws TicketCreationFailed
     */
    protected function createCancellationTicket()
    {
        $message = sprintf(
            'Server with billing ID %d has been terminated.',
            $this->server->currentBillingId()
        );

        $this->ticket->create([
            'clientid' => $this->config->get('userid'),
            'subject'  => 'Server Termination',
            'message'  => $message,
        ]);
    }
}
