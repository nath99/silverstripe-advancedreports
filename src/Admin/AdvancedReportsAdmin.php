<?php

namespace SilverstripeAustralia\AdvancedReports\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\View\Requirements;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Core\ClassInfo;
use SilverstripeAustralia\AdvancedReports\Admin\AdvancedReportsAdminItemRequest;
use SilverstripeAustralia\AdvancedReports\Models\AdvancedReport;

/**
 * Provides an interface for creating, managing, and generating reports.
 */
class AdvancedReportsAdmin extends ModelAdmin
{

    private static $menu_title = 'Advanced Reports';

    private static $url_segment = 'advanced-reports';

    private static $menu_icon = 'silverstripe-australia/advancedreports:images/bar-chart.png';

    private static $model_importers = array();

    private $managedModels;

    public function init()
    {
        parent::init();

        Requirements::javascript('silverstripe-australia/advancedreports:javascript/jquery.min.js');
        Requirements::javascript('silverstripe-australia/advancedreports:javascript/advanced-report-settings.js');
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        $name = $this->sanitiseClassName($this->modelClass);
        $grid = $form->Fields()->dataFieldByName($name);

        $grid->getConfig()->getComponentByType(GridFieldDetailForm::class)->setItemRequestClass(
            AdvancedReportsAdminItemRequest::class
        );

        return $form;
    }

    public function getList()
    {
        return parent::getList()->filter('ReportID', 0);
    }

    /**
     * If no managed models are explicitly defined, then default to displaying
     * all available reports.
     *
     * @return array
     */
    public function getManagedModels()
    {
        if ($this->managedModels !== null) {
            return $this->managedModels;
        }

        if ($this->config()->get('managed_models')) {
            $result = parent::getManagedModels();
        } else {
            $classes = ClassInfo::subclassesFor(AdvancedReport::class);
            $result = array();

            array_shift($classes);

            foreach ($classes as $class) {
                $result[$class] = array('title' => singleton($class)->singular_name());
            }
        }

        return $this->managedModels = $result;
    }
}
