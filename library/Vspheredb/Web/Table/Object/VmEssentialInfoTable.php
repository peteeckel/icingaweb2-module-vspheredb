<?php

namespace Icinga\Module\Vspheredb\Web\Table\Object;

use gipfl\IcingaWeb2\Link;
use gipfl\Translation\TranslationHelper;
use gipfl\IcingaWeb2\Widget\NameValueTable;
use Exception;
use Icinga\Module\Vspheredb\Addon\BackupTool;
use Icinga\Module\Vspheredb\Addon\IbmSpectrumProtect;
use Icinga\Module\Vspheredb\Addon\VeeamBackup;
use Icinga\Module\Vspheredb\Addon\VRangerBackup;
use Icinga\Module\Vspheredb\DbObject\MonitoringConnection;
use Icinga\Module\Vspheredb\DbObject\VCenter;
use Icinga\Module\Vspheredb\DbObject\VirtualMachine;
use Icinga\Module\Vspheredb\EventHistory\VmRecentMigrationHistory;
use Icinga\Module\Vspheredb\Json;
use Icinga\Module\Vspheredb\PathLookup;
use Icinga\Module\Vspheredb\Web\Widget\IcingaHostStatusRenderer;
use Icinga\Module\Vspheredb\Web\Widget\Link\KnowledgeBaseLink;
use Icinga\Module\Vspheredb\Web\Widget\Link\VmrcLink;
use Icinga\Module\Vspheredb\Web\Widget\Renderer\GuestToolsVersionRenderer;
use Icinga\Module\Vspheredb\Web\Widget\SubTitle;
use ipl\Html\Html;

class VmEssentialInfoTable extends NameValueTable
{
    use TranslationHelper;

    /** @var VirtualMachine */
    protected $vm;

    /** @var VCenter */
    protected $vCenter;

    public function __construct(VirtualMachine $vm)
    {
        $this->vm = $vm;
        $this->prepend(new SubTitle($this->translate('Information'), 'info-circled'));
        $this->vCenter = VCenter::load($vm->get('vcenter_uuid'), $vm->getConnection());
    }

    protected function getDb()
    {
        return $this->vm->getConnection();
    }

    /**
     * @param $annotation
     * @return string|\ipl\Html\HtmlElement
     */
    protected function formatAnnotation($annotation)
    {
        $tools = [
            new IbmSpectrumProtect(),
            new VeeamBackup(),
            new VRangerBackup(),
        ];
        /** @var BackupTool $tool */
        foreach ($tools as $tool) {
            $tool->stripAnnotation($annotation);
        }

        $annotation = trim($annotation);

        if (strpos($annotation, "\n") === false) {
            return $annotation;
        } else {
            return Html::tag('pre', null, $annotation);
        }
    }

    /**
     * @throws \Icinga\Exception\NotFoundError
     */
    protected function assemble()
    {
        $vm = $this->vm;
        $uuid = $vm->get('uuid');
        if ($annotation = $vm->get('annotation')) {
            $this->addNameValueRow(
                $this->translate('Annotation'),
                $this->formatAnnotation($annotation)
            );
        }
        if ($customVars = $vm->get('custom_values')) {
            foreach (Json::decode($customVars) as $name => $value) {
                $this->addNameValueRow($name, $value);
            }
        }

        /** @var \Icinga\Module\Vspheredb\Db $connection */
        $connection = $vm->getConnection();
        $lookup =  new PathLookup($connection);

        if ($guestName = $vm->get('guest_full_name')) {
            $guest = sprintf(
                '%s (%s)',
                $guestName,
                $vm->get('guest_id')
            );
        } else {
            $guest = '-';
        }

        /*
        $this->addNameValueRow(
            $this->translate('Monitoring'),
            $this->getMonitoringInfo($vm)
        );
        */

        $guestInfo = Html::sprintf(
            '%s %s (Guest %s)',
            $this->getGuestToolsVersionInfo($vm),
            $vm->get('guest_tools_running_status'),
            $vm->get('guest_state')
        );
        if ($vm->get('guest_id')) {
            $this->addNameValuePairs([
                $this->translate('Console') => new VmrcLink($this->vCenter, $vm),
                $this->translate('Guest OS') => $guest,
                $this->translate('Guest IP') => $vm->get('guest_ip_address') ?: '-',
                $this->translate('Guest Hostname') => $vm->get('guest_host_name') ?: '-',
                $this->translate('Guest Utilities') => $guestInfo,
            ]);
        } else {
            $this->addNameValuePairs([
                $this->translate('Guest Utilities') => $guestInfo,
            ]);
        }

        $migrations = new VmRecentMigrationHistory($vm);
        $cntMigrations = $migrations->countWeeklyMigrationAttempts();
        $this->addNameValueRow(
            $this->translate('Migrations'),
            Html::sprintf(
                $this->translate('%s %s took place during the last 7 days'),
                $cntMigrations,
                Link::create(
                    $this->translate('VMotion attempt(s)'),
                    'vspheredb/vm/events',
                    ['uuid' => bin2hex($uuid)]
                )
            )
        );
    }

    protected function getGuestToolsVersionInfo($vm)
    {
        $info = $vm->get('guest_tools_version');
        if ($info === null) {
            return '-';
        }

        $info = (string) $info;

        if ($info === '2147483647') {
            $info = [$info, Html::sprintf(
                ' (' . $this->translate('read %s for details') . ')',
                new KnowledgeBaseLink(
                    51988,
                    'vSphere Client displays the VMTools version as 2147483647 for FreeBSD open-vm-tools'
                )
            )];
        } else {
            $renderer = new GuestToolsVersionRenderer();
            $info = $renderer($info);
        }

        return $info;
    }

    /**
     * @param VirtualMachine $vm
     * @return array|null
     */
    protected function getMonitoringInfo(VirtualMachine $vm)
    {
        $name = $vm->get('guest_host_name');
        $statusRenderer = new IcingaHostStatusRenderer();
        try {
            $monitoring = MonitoringConnection::eventuallyLoadForVCenter($this->vCenter);
            if ($monitoring && $monitoring->hasHost($name)) {
                $monitoringState = $monitoring->getHostState($name);
                return [
                    // TODO: is_acknowledged, is_in_downtime
                    $statusRenderer($monitoringState->current_state),
                    ' ',
                    $monitoringState->output,
                    ' ',
                    Link::create(
                        $this->translate('more'),
                        'monitoring/host/show',
                        ['host' => $name],
                        ['class' => 'icon-right-small']
                    )
                ];
            } else {
                return [Html::sprintf(
                    "There is no monitored Host mapped to this VM"
                )];
            }
        } catch (Exception $e) {
            return [
                Html::tag('p', ['class' => 'error'], sprintf(
                    $this->translate('Unable to check monitoring state: %s'),
                    $e->getMessage()
                ))
            ];
        }
    }
}
