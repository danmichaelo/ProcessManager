<?php

namespace ProcessManager;

use Pimcore\API\Plugin as PluginLib;
use Pimcore\Log\Maintenance;

class Plugin extends PluginLib\AbstractPlugin implements PluginLib\PluginInterface
{
    use ExecutionTrait;

    public static $maintenanceOptions = [
        'autoCreate' => true,
        'name' => 'ProcessManager maintenance',
    ];

    protected static $_pluginConfig = null;

    protected static $monitoringItem;

    const PLUGIN_NAME = 'ProcessManager';

    const TABLE_NAME_CONFIGURATION = 'plugin_process_manager_configuration';
    const TABLE_NAME_MONITORING_ITEM = 'plugin_process_manager_monitoring_item';

    public function init(){
        parent::init();
        \Pimcore::getEventManager()->attach('system.console.init', function (\Zend_EventManager_Event $e) {
            $application = $e->getTarget();
            $application->add(new \ProcessManager\Console\Command\ClassMethodExecutorCommand());
            $application->add(new \ProcessManager\Console\Command\SampleCommand());
            $application->add(new \ProcessManager\Console\Command\MaintenanceCommand());
        });
    }

    /**
     * @return \ProcessManager\MonitoringItem
     */
    public static function getMonitoringItem()
    {
        return self::$monitoringItem;
    }

    public static function maintenance(){
        $config = self::getPluginConfig();
        if($config['general']['executeWithMaintenance']){
            self::initProcessManager(null,self::$maintenanceOptions);
            $maintenance = new \ProcessManager\Maintenance();
            $maintenance->execute();
        }
    }

    /**
     * @param mixed $monitoringItem
     */
    public static function setMonitoringItem($monitoringItem)
    {
        self::$monitoringItem = $monitoringItem;
    }

    public static function install()
    {
        $updater = new Updater();
        $updater->install();
    }
    
    public static function uninstall()
    {
        // implement your own logic here
        return true;
    }

    public static function isInstalled()
    {
        $config = self::getPluginConfig();
        return !empty($config);
    }

    public static function getLogDir(){
        $dir = PIMCORE_WEBSITE_VAR . '/log/process-manager/';
        if(!is_dir($dir)){
            \Pimcore\File::mkdir($dir);
        }
        return $dir;
    }

    public static function needsReloadAfterInstall(){
        return true;
    }

    public static function getTranslationFile($language){
        if (is_file(PIMCORE_PLUGINS_PATH . "/" . self::PLUGIN_NAME . "/texts/" . $language . ".csv")) {
            return "/" . self::PLUGIN_NAME . "/texts/" . $language . ".csv";
        } else {
            return "/" . self::PLUGIN_NAME . "/texts/en.csv";
        }
    }


    public static function getConfigFilePath()
    {
        if(!is_dir(PIMCORE_WEBSITE_PATH . '/var/plugins/' . self::PLUGIN_NAME))  {
            mkdir(PIMCORE_WEBSITE_PATH . '/var/plugins/' . self::PLUGIN_NAME, 0755, true);
        }
        return PIMCORE_WEBSITE_PATH . '/var/plugins/' . self::PLUGIN_NAME.'/config.xml';
    }

    public static function getPluginConfig()
    {
        if (is_null(self::$_pluginConfig)) {
            self::$_pluginConfig = include \Pimcore\Config::locateConfigFile("plugin-process-manager.php");
        }
        return self::$_pluginConfig;
    }

    public static function shutdownHandler($arguments){
        /**
         * @var $monitoringItem \ProcessManager\MonitoringItem
         */
        if($monitoringItem = \ProcessManager\Plugin::getMonitoringItem()){

            $error = error_get_last();
            if(in_array($error['type'],[E_WARNING,E_DEPRECATED,E_STRICT,E_NOTICE])){
                if($config = Configuration::getById($monitoringItem->getConfigurationId())){
                    $versions = $config->getKeepVersions();
                    if(is_numeric($versions)){
                        $list = new MonitoringItem\Listing();
                        $list->setOrder('DESC')->setOrderKey('id')->setOffset((int)$versions)->setLimit(100000000000); //a limit has to defined otherwise the offset wont work
                        $list->setCondition('status ="finished" AND configurationId=? AND IFNULL(pid,0) != ? ',[$config->getId(),$monitoringItem->getPid()]);

                        $items = $list->load();
                        foreach($items as $item){
                            $item->delete();
                        }
                    }
                }
                if(!$monitoringItem->getMessage()){
                    $monitoringItem->setMessage('finished');
                }
                $monitoringItem->setCompleted();
                $monitoringItem->setPid(null)->save();


            }else{
                $monitoringItem->setMessage('ERROR:' . print_r($error,true).$monitoringItem->getMessage());
                $monitoringItem->setPid(null)->setStatus($monitoringItem::STATUS_FAILED)->save();
            }
        }
    }

    public static function startup($arguments) {
        $monitoringItem = $arguments['monitoringItem'];
        if($monitoringItem instanceof \ProcessManager\MonitoringItem){
            $monitoringItem->resetState()->save();
            $monitoringItem->setPid(getmypid());
            $monitoringItem->setStatus($monitoringItem::STATUS_RUNNING);
            $monitoringItem->save();
        }
    }
}
