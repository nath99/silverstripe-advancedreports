<?php

namespace SilverstripeAustralia\AdvancedReports\Models;

use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\DataList;

/**
 * An AdvancedReport type that allows a user to select the type they want
 * to report on.
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class DataObjectReport extends AdvancedReport
{
    private static $table_name = "AdvancedReports_DataObjectReport";

    private static $db = array(
        'ReportOn'            => 'Varchar(64)',
    );

    public function getReportName()
    {
        return "Generic Report";
    }

    protected function getReportableFields()
    {
        $fields = array(
            'ID' => 'ID',
            'Created' => 'Created',
            'LastEdited' => 'LastEdited',
        );

        if ($this->ReportOn) {
            $config = Config::forClass($this->ReportOn);

            $db = $config->get('db');
            $hasOne = $config->get('has_one');

            if ($db) {
                $fields = array_merge($fields, $db);
            }

            if ($hasOne) {
                foreach (array_keys($hasOne) as $name) {
                    $fields[$name . 'ID'] = true;
                }
            }

            $fields = array_combine(array_keys($fields), array_keys($fields));
        }

        ksort($fields);

        return $fields;
    }

    public function getSettingsFields()
    {
        $fields = parent::getSettingsFields();
        $types = ClassInfo::subclassesFor(DataObject::class);

        array_shift($types);
        ksort($types);

        $fields->insertAfter(
            new DropdownField('ReportOn', _t('AdvancedReport.REPORT_ON', 'Report on'), $types),
            'Title'
        );

        return $fields;
    }

    /**
     * @return DataList
     */
    public function getDataObjects()
    {
        return DataList::create($this->ReportOn)
            ->filter($this->getFilter())
            ->sort($this->getSort());
    }

    /**
     * Gets the filter we need for the report
     *
     * @param $agreementFilter
     * @return string
     */
    protected function getFilter()
    {
        return $this->getConditions();
    }
}
