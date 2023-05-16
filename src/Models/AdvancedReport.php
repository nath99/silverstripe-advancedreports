<?php

namespace SilverstripeAustralia\AdvancedReports\Models;

use Exception;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Core\Convert;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Control\Controller;
use SilverStripe\View\Requirements;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\FileNameFilter;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverstripeAustralia\AdvancedReports\Formatters\ReportFieldFormatter;
use SilverstripeAustralia\AdvancedReports\Formatters\ReportFormatter;
use Symbiote\MultiValueField\Fields\MultiValueDropdownField;
use Symbiote\MultiValueField\Fields\MultiValueTextField;
use Symbiote\MultiValueField\Fields\KeyValueField;

/**
 * A representation of a report in the system
 *
 * Provides several fields for specifying basic parameters of reports,
 * and functionality for (relatively) simply building an SQL query for
 * retrieving the report data.
 *
 * A ReportPage makes use of a reportformatter to actually generate the
 * report that gets displayed to the user; this report formatter uses
 * one of these AdvancedReport objects to actually get all the relevant
 * information to be displayed.
 *
 * @author marcus@silverstripe.com.au
 * @license http://silverstripe.org/bsd-license/
 */
class AdvancedReport extends DataObject implements PermissionProvider
{

    /**
     * What conversion needs to occur?
     *
     * @var array
     * @config
     */
    private static $conversion_formats = array('pdf' => 'html');

    /**
     * Enables or disables PDF generation using the PDF rendition module.
     *
     * By default PDF generation is enabled if the PDF rendition module is
     * installed.
     *
     * @var bool
     * @config
     */
    private static $generate_pdf = false;

    /**
     * Do we allow groupby and sum settings?
     *
     * Child classes can set this to true
     *
     * @var boolean
     */
    private static $allow_grouping = false;

    /**
     * A list of allowed filter conditions.
     *
     * @var array
     * @config
     */
    private static $allowed_conditions = array(
        'ExactMatch' => '=',
        'ExactMatch:not' => '!=',
        'GreaterThanOrEqual' => '>=',
        'GreaterThan' => '>',
        'LessThan' => '<',
        'LessThanOrEqual' => '<=',
        'InList' => 'In List',
        'IsNull' => 'IS NULL',
        'IsNull:not' => 'IS NOT NULL'
    );

    private static $table_name = "AdvancedReports_AdvancedReport";

    private static $db = array(
        'Title'                        => 'Varchar(128)',
        'GeneratedReportTitle'        => 'Varchar(128)',
        'Description'                => 'Text',
        'ReportFields'                => 'MultiValueField',
        'ReportHeaders'                => 'MultiValueField',
        'ConditionFields'            => 'MultiValueField',
        'ConditionOps'                => 'MultiValueField',
        'ConditionValues'            => 'MultiValueField',
        'PaginateBy'                => 'Varchar(64)',        // a field used to separate tables (eg financial years)
        'PageHeader'                => 'Varchar(64)',        // used as a keyworded string for pages

        // optional fields that child classes will need to provide implementation for
        'GroupBy'                    => 'MultiValueField',
        'SumFields'                    => 'MultiValueField',

        'SortBy'                    => 'MultiValueField',
        'SortDir'                    => 'MultiValueField',
        'ClearColumns'                => 'MultiValueField',
        'AddInRows'                    => 'MultiValueField',    // which fields in each row should be added?
        'AddCols'                    => 'MultiValueField',    // Which columns should be added ?
        'NumericSort'                => 'MultiValueField',    // columns to be numericly sorted
        'ReportParams'                => 'MultiValueField',    // provide some defaults for parameterised reports
        'FieldFormattingField'        => 'MultiValueField',    // list of fields which should be formated somehow
        'FieldFormattingFormatter'    => 'MultiValueField',    // list of used formatter for this
    );

    private $field_labels = array(
        'ReportFields' => 'Fields',
        'ReportHeaders' => 'Field Headers',
        'ConditionFields' => 'Conditions',
        'PaginateBy' => 'Paginate By',
        'SortBy' => 'Sort Field',
        'SortDir' => 'Sort Order',
    );


    private static $has_one = array(
        'Report' => AdvancedReport::class,            // never set for the 'template' report for a page, but used to
        // list all the generated reports.
        'HTMLFile' => File::class,
        'CSVFile' => File::class,
        'PDFFile' => File::class,
    );

    private static $has_many = array(
        'Reports'        => AdvancedReport::class,
    );

    private static $default_sort = "Title ASC";

    private static $searchable_fields = array(
        'Title',
        'Description',
    );

    private static $summary_fields = array(
        'Title',
        'Description'
    );

    /**
     * Should generated report contents be stored on the file object?
     *
     * @var boolean
     */
    private static $store_file_content = false;

    /**
     * Gets the form fields for display in the CMS.
     *
     * The actual report configuration fields should be generated by
     * {@link getSettingsFields()}, so they can also be used in the front end.
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        Requirements::css('silverstripe-australia/advancedreports:css/cms.css');

        $fields = new FieldList(array(
            new TabSet('Root', new Tab(
                'Main',
                new GridField(
                    'GeneratedReports',
                    _t('AdvancedReport.GENERATED_REPORTS', 'Generated Reports'),
                    $this->Reports()->sort('Created', 'DESC'),
                    $config = GridFieldConfig_Base::create()
                        ->addComponent(new GridFieldDeleteAction())
                )
            ))
        ));

        /** @var GridFieldDataColumns */
        $columns = $config->getComponentByType(GridFieldDataColumns::class);

        $columns->setDisplayFields(array(
            'Title' => _t('AdvancedReport.TITLE', 'Title'),
            'Created' => _t('AdvancedReport.GENERATED_AT', 'Generated At'),
            'Links' => _t('AdvancedReport.LINKS', 'Links')
        ));

        $columns->setFieldFormatting(array(
            'Links' => function ($value, $item) {
                $result = '';
                $links = array('html', 'csv');

                if ($item->config()->generate_pdf) {
                    $links[] = 'pdf';
                }

                foreach ($links as $type) {
                    $result .= sprintf(
                        '<a href="%s" target="_blank" class="advanced-report-download-link">%s</a>',
                        $item->getFileLink($type),
                        strtoupper($type)
                    );
                }

                return $result;
            }
        ));

        if ($this->isInDB() && $this->canGenerate()) {
            $options = array(
                'html' => 'HTML', 'csv' => 'CSV', 'pdf' => 'PDF'
            );
            if (!class_exists('PDFRenditionService')) {
                unset($options['pdf']);
            }

            $fields->addFieldsToTab(
                'Root.Main',
                array(
                    DropdownField::create('PreviewFormat')
                        ->setTitle(_t('AdvancedReport.PREVIEW_FORMAT', 'Preview format'))
                        ->setSource($options),
                    TextField::create('GeneratedReportTitle')
                        ->setTitle(_t('AdvancedReport.GENERATED_TITLE', 'Generated report title'))
                        ->setValue($this->Title)
                ),
                'GeneratedReports'
            );
        }

        if ($this->canEdit()) {
            $fields->addFieldsToTab('Root.Settings', $this->getSettingsFields());
        }

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    /**
     * Gets the form fields for configuring the report settings.
     *
     * @return FieldList
     */
    public function getSettingsFields()
    {
        $reportable = $this->getReportableFields();
        $converted = array();

        foreach ($reportable as $k => $v) {
            if (preg_match('/^(.*) +AS +"([^"]*)"/i', $k, $matches)) {
                $k = $matches[2];
            }
            $converted[$this->dottedFieldToUnique($k)] = $v;
        }

        $fieldsGroup = new FieldGroup(
            'Fields',
            MultiValueDropdownField::create('ReportFields')
                ->setTitle(_t('AdvancedReport.REPORT_FIELDS', 'Report Fields'))
                ->setSource($reportable)
                ->addExtraClass('advanced-report-field-names'),
            MultiValueTextField::create('ReportHeaders')
                ->setTitle(_t('AdvancedReport.REPORT_HEADERS', 'Headers'))
                ->addExtraClass('advanced-report-field-headers')
        );
        $fieldsGroup->setName('FieldsGroup');
        $fieldsGroup->addExtraClass('advanced-report-fields dropdown');

        $conditionsGroup = new FieldGroup(
            'Conditions',
            new MultiValueDropdownField(
                'ConditionFields',
                _t('AdvancedReport.CONDITION_FIELDS', 'Condition Fields'),
                $reportable
            ),
            new MultiValueDropdownField(
                'ConditionOps',
                _t('AdvancedReport.CONDITION_OPERATIONS', 'Operation'),
                $this->config()->allowed_conditions
            ),
            new MultiValueTextField(
                'ConditionValues',
                _t('AdvancedReport.CONDITION_VALUES', 'Value')
            )
        );
        $conditionsGroup->setName('ConditionsGroup');
        $conditionsGroup->addExtraClass('dropdown');

        // define the group for the sort field
        $sortGroup = new FieldGroup(
            'Sort',
            new MultiValueDropdownField(
                'SortBy',
                _t('AdvancedReport.SORTED_BY', 'Sorted By'),
                $reportable
            ),
            new MultiValueDropdownField(
                'SortDir',
                _t('AdvancedReport.SORT_DIRECTION', 'Sort Direction'),
                array(
                    'ASC' => _t('AdvancedReport.ASC', 'Ascending'),
                    'DESC' => _t('AdvancedReport.DESC', 'Descending')
                )
            )
        );
        $sortGroup->setName('SortGroup');
        $sortGroup->addExtraClass('dropdown');


        // build a list of the formatters
        $formatters = ClassInfo::implementorsOf(ReportFieldFormatter::class);
        $fmtrs = array();
        foreach ($formatters as $formatterClass) {
            $formatter = new $formatterClass();
            $fmtrs[$formatterClass] = $formatter->label();
        }

        // define the group for the custom field formatters
        $fieldFormattingGroup = new FieldGroup(
            _t('AdvancedReport.FORMAT_FIELDS', 'Custom field formatting'),
            new MultiValueDropdownField(
                'FieldFormattingField',
                _t('AdvancedReport.FIELDFORMATTING', 'Field'),
                $converted
            ),
            new MultiValueDropdownField(
                'FieldFormattingFormatter',
                _t('AdvancedReport.FIELDFORMATTINGFORMATTER', 'Formatter'),
                $fmtrs
            )
        );
        $fieldFormattingGroup->setName('FieldFormattingGroup');
        $fieldFormattingGroup->addExtraClass('dropdown');

        // assemble the fieldlist
        $fields = new FieldList(
            new TextField('Title', _t('AdvancedReport.TITLE', 'Title')),
            new TextareaField(
                'Description',
                _t('AdvancedReport.DESCRIPTION', 'Description')
            ),
            $fieldsGroup,
            $conditionsGroup,
            new KeyValueField(
                'ReportParams',
                _t('AdvancedReport.REPORT_PARAMETERS', 'Default report parameters')
            ),
            $sortGroup,
            new MultiValueDropdownField(
                'NumericSort',
                _t('AdvancedReport.SORT_NUMERICALLY', 'Sort these fields numerically'),
                $reportable
            ),
            DropdownField::create('PaginateBy')
                ->setTitle(_t('AdvancedReport.PAGINATE_BY', 'Paginate By'))
                ->setSource($reportable)
                ->setHasEmptyDefault(true),
            TextField::create('PageHeader')
                ->setTitle(_t('AdvancedReport.HEADER_TEXT', 'Header text'))
                ->setDescription(_t('AdvancedReport.USE_NAME_FOR_PAGE_NAME', 'use $name for the page name'))
                ->setValue('$name'),
            new MultiValueDropdownField(
                'AddInRows',
                _t('AdvancedReport.ADD_IN_ROWS', 'Add these columns for each row'),
                $converted
            ),
            new MultiValueDropdownField(
                'AddCols',
                _t('AdvancedReport.ADD_IN_ROWS', 'Provide totals for these columns'),
                $converted
            ),
            $fieldFormattingGroup,
            new MultiValueDropdownField(
                'ClearColumns',
                _t('AdvancedReport.CLEARED_COLS', '"Cleared" columns'),
                $converted
            )
        );

        if ($this->config()->allow_grouping) {
            // GroupBy
            $groupingGroup = new FieldGroup(
                'Grouping',
                new MultiValueDropdownField(
                    'GroupBy',
                    _t('AdvancedReport.GROUPBY_FIELDS', 'Group by fields'),
                    $reportable
                ),
                new MultiValueDropdownField(
                    'SumFields',
                    _t('AdvancedReport.SUM_FIELDS', 'SUM fields'),
                    $reportable
                )
            );
            $groupingGroup->addExtraClass('dropdown');
            $fields->insertAfter($groupingGroup, 'Conditions');
        }


        if ($this->hasMethod('updateReportFields')) {
            Deprecation::notice(
                '3.0',
                'The updateReportFields method is deprecated, instead overload getSettingsFields'
            );

            $this->updateReportFields($fields);
        }

        $this->extend('updateSettingsFields', $fields);
        return $fields;
    }

    /**
     * Prepare and generate this report into report instances
     *
     * @return AdvancedReport
     */
    public function prepareAndGenerate()
    {
        $report = $this->duplicate(false);
        $report->ReportID = $this->ID;
        $report->Created = DBDatetime::now();
        $report->LastEdited = DBDatetime::now();
        $report->Title = $this->GeneratedReportTitle;
        $report->write();

        $report->generateReport('html');
        $report->generateReport('csv');
        if ($this->config()->generate_pdf) $report->generateReport('pdf');

        return $report;
    }

    /**
     * Get a link to a specific instance of this report.
     *
     * @param string $type
     * @return string
     */
    public function getFileLink($type)
    {
        $file = $this->{strtoupper($type) . File::class}(); // ->Link();
        if ($this->config()->store_file_content) {
            return Controller::join_links(
                'admin/advanced-reports/AdvancedDisruptionReport/EditForm/field/AdvancedDisruptionReport/item',
                $this->ID,
                'viewreport',
                $file->Name
            );
        } else {
            return $file->Link();
        }
    }

    /**
     * Abstract method; actual reports should define this.
     */
    public function getReportName()
    {
        throw new Exception("Abstract method called; please implement getReportName()");
    }

    /**
     * Gets an array of field names that can be used in this report
     *
     * Override to specify your own values.
     */
    protected function getReportableFields()
    {
        return array('Title' => 'Title');
    }

    /**
     * Converts a field in dotted notation (as used in some report selects) to a unique name
     * that can be used for, eg "Table.Field AS Table_Field" so that we don't have problems with
     * duplicity in queries, and mapping them back and forth
     *
     * We keep this as a method to ensure that we're explicity as to what/why we're doing
     * this so that when someone comes along later, it's not toooo wtfy
     *
     * @param string $field
     * @return string
     */
    public function dottedFieldToUnique($field)
    {
        return str_replace('.', '_', $field);
    }

    /**
     * Determine the class that defines the given field.
     *
     * This will look through all parent classes and return the class that has a dbtable that defines the
     * field.
     *
     * @param string $type
     *				The base data object type the field is being referenced in
     * @param string $field
     *				The field being referenced
     * @return string
     */
    protected function tableSpacedField($type, $field)
    {
        $types = ClassInfo::ancestry($type, true);
        $class = '';
        foreach (array_reverse($types) as $class) {
            // check its DB and whether it defines the field
            $db = Config::inst()->get($class, 'db', Config::UNINHERITED);
            if (isset($db[$field])) {
                break;
            }
        }

        if (!$class) {
            $class = $type;
        }
        // if we fall through to here, we assume that we're just going to use the base data table
        return '"' . Convert::raw2sql($class) . '"."' . Convert::raw2sql($field) . '"';
    }

    /**
     * Return the 'included fields' list.
     *
     * @return
     */
    public function getHeaders()
    {
        $headers = array();

        $reportFields = $this->getReportableFields();
        $sel = $this->ReportFields->getValues();
        $headerTitles = $this->ReportHeaders->getValues();
        $selected = array();

        for ($i = 0, $c = count($sel); $i < $c; $i++) {
            $field = $sel[$i];

            if (preg_match('/^(.*) +AS +"?([^"]*)"?/i', $field, $matches)) {
                $field = $matches[2];
            }

            $fieldName = $this->dottedFieldToUnique($field);

            if (isset($selected[$field])) {
                $selected[$field]++;
                $fieldName .= '_' . $selected[$field];
            }

            if (isset($headerTitles[$i])) {
                $headers[$fieldName] = $headerTitles[$i];
            } else {
                $headers[$fieldName] = (isset($reportFields[$field]) ? $reportFields[$field] : $field);
            }

            if (!isset($selected[$field])) {
                $selected[$field] = 1;
            }
        }

        return $headers;
    }

    /**
     * Retrieve the raw data objects set for this report
     *
     * Note that the "DataObjects" don't necessarily need to implement DataObjectInterface;
     * we can return whatever objects (or array maps) that we like.
     *
     */
    public function getDataObjects()
    {
        throw new Exception("Abstract method called; please implement getDataObjects()");
    }

    /**
     * Get the selected report fields in a format suitable to be put in an
     * SQL select (an array format)
     *
     * @return array
     */
    protected function getReportFieldsForQuery()
    {
        $fields = $this->ReportFields->getValues();
        $reportFields = $this->getReportableFields();
        $sortVals = $this->SortBy->getValues();

        if (!$sortVals) {
            $sortVals = array();
        }

        $toSelect = array();
        $selected = array();

        // make sure our sortvals are in the query too
        foreach ($sortVals as $sortOpt) {
            if (!in_array($sortOpt, $fields)) {
                $fields[] = $sortOpt;
            }
        }

        foreach ($fields as $field) {
            if (isset($reportFields[$field])) {
                $fieldName = $field;
                if (strpos($field, ' AS ')) {
                    // do nothing to the field??
                } else if (strpos($field, '.')) {
                    $parts = explode('.', $field);
                    $sep = '';
                    $quotedField = implode('"."', $parts);

                    if (isset($selected[$fieldName])) {
                        $selected[$fieldName]++;
                        $field = $field . '_' . $selected[$fieldName];
                    }

                    $field = '"' . $quotedField . '" AS "' . $this->dottedFieldToUnique($field) . '"';
                } else {
                    if (isset($selected[$fieldName])) {
                        $selected[$fieldName]++;
                        $field = '"' . $field . '" AS "' . $field . '_' . $selected[$fieldName] . '"';
                    } else {
                        $field = '"' . $field . '"';
                    }
                }
                $toSelect[] = $field;
            }

            if (!isset($selected[$fieldName])) {
                $selected[$fieldName] = 1;
            }
        }

        return $toSelect;
    }

    /**
     * Return an array of FieldValuePrefix => Callable
     * filters for changing the values of the condition value
     *
     * This is so that you can do things like strtotime() in conditions for
     * a date field, for example.
     *
     * Everything AFTER the prefix given here is passed through to the
     * callable, so you can handle the passing of parameters manually
     * if needed
     *
     * @return array
     */
    protected function getConditionFilters()
    {
        $defaultFilters = new ConditionFilters();

        return array(
            'strtotime:'        => array($defaultFilters, 'strtotimeDateValue'),
            'param:'            => array($defaultFilters, 'paramValue'),
        );
    }

    /**
     * Generate a WHERE clause based on the input the user provided.
     *
     * Assumes the user has provided some values for the $this->ConditionFields etc. Converts
     * everything to an array that is run through the dbQuote() util method that handles all the
     * escaping
     *
     * @param $defaults
     *			Some hardcoded default conditions applied
     *
     * @param $withOperands
     *			Whether the return should be as SQL operands instead of ORM filters
     *
     * @return array
     */
    public function getConditions($defaults = array(), $withOperands = false)
    {
        $reportFields = $this->getReportableFields();
        $fields = $this->ConditionFields->getValues();
        if (!$fields || !count($fields)) {
            return array();
        }

        $ops = $this->ConditionOps->getValues();
        $vals = $this->ConditionValues->getValues();

        $filter = $defaults;
        $conditions = $this->config()->allowed_conditions;
        $conditionFilters = $this->getConditionFilters();

        for ($i = 0, $c = count($fields); $i < $c; $i++) {
            $field = $fields[$i];
            if (!isset($ops[$i]) || !isset($vals[$i])) {
                continue;
            }

            $op = $ops[$i];
            if (!isset($conditions[$op])) {
                continue;
            }

            $originalVal = $val = $vals[$i];
            $val = $this->applyFiltersToValue($originalVal);

            switch ($op) {
                case 'InList': {
                        $op = 'ExactMatch';
                        $val = explode(',', $val);
                        break;
                    }
                case 'IS':
                case 'IS NOT': {
                        if (strtolower($val) == 'null') {
                            $val = null;
                        }
                        break;
                    }
            }

            if ($withOperands) {
                $rawOp = $conditions[$op];
                if (is_array($val)) {
                    $rawOp = 'IN';
                    $filter[] = $field . ' ' . $rawOp . ' (' . implode(',', Convert::raw2sql($val)) . ')';
                } else {
                    $filter[] = $field . ' ' . $rawOp . ' \'' . Convert::raw2sql($val) . '\'';
                }
            } else {
                $filter[$field . ':' . $op] = $val;
            }
        }

        return $filter;
    }

    /**
     * Apply some filters to a condition value for use in a query
     *
     * @param string $originalVal
     * @return string
     */
    public function applyFiltersToValue($originalVal)
    {
        $filters = $this->getConditionFilters();

        foreach ($filters as $prefix => $callable) {
            if (strpos($originalVal, $prefix) === 0) {
                $val = substr($originalVal, strlen($prefix));
                return call_user_func($callable, $val, $this);
            }
        }

        return $originalVal;
    }


    /**
     * Helper method that applies the given filters to a specific DataQuery object
     *
     * Replicates similar functionality in DataList
     *
     * @param DataQuery $dataQuery
     * @param array $filterArray
     */
    protected function getWhereClause($filterArray, $baseType)
    {
        $parts = array();
        $allowed = self::config()->allowed_conditions;

        if (is_array($filterArray) && count($filterArray)) {
            foreach ($filterArray as $field => $value) {
                $fieldArgs = explode(':', $field);
                $field = array_shift($fieldArgs);
                $filterType = array_shift($fieldArgs);
                $modifiers = $fieldArgs;
                $originalFilter = $filterType;
                if (count($modifiers)) {
                    $originalFilter = $originalFilter . ':' . implode(':', $modifiers);
                }

                if (!isset($allowed[$originalFilter])) {
                    continue;
                }

                // actually escape the field
                if (!strpos($field, '.')) {
                    $field = $this->tableSpacedField($baseType, $field);
                }

                $operator = $allowed[$originalFilter];

                $parts[$field . ' ' . $operator] = $value;
            }
        }


        $where = '';

        if (count($parts)) {
            $where = $this->dbQuote($parts);
        }
        return $where;
    }

    /**
     * Gets a string that represents the possible 'sort' options.
     *
     * @return string
     */
    protected function getSort()
    {
        $sortBy = '';
        $sortVals = $this->SortBy->getValues();
        $dirs = $this->SortDir->getValues();

        $dir = 'ASC';

        $reportFields = $this->getReportableFields();
        $numericSort = $this->getNumericSortFields();

        if (count($sortVals)) {
            $sep = '';
            $index = 0;
            foreach ($sortVals as $sortOpt) {
                // check we're not injecting an invalid sort
                if (isset($reportFields[$sortOpt])) {
                    // update the dir to match, if available, otherwise just use the last one
                    if (isset($dirs[$index])) {
                        if (in_array($dirs[$index], array('ASC', 'DESC'))) {
                            $dir = $dirs[$index];
                        }
                    }

                    $sortOpt = $this->dottedFieldToUnique($sortOpt);

                    // see http://blog.feedmarker.com/2006/02/01/how-to-do-natural-alpha-numeric-sort-in-mysql/
                    // for why we're + 0 here. Basically, coercing an alphanum sort instead of straight string
                    if (is_array($numericSort) && in_array($sortOpt, $numericSort)) {
                        $sortOpt .= '+0';
                    }
                    $sortBy .= $sep . $sortOpt . ' ' . $dir;
                    $sep = ', ';
                }
                $index++;
            }
        } else {
            $sortBy = 'ID ' . $dir;
        }

        return $sortBy;
    }

    /**
     * Return any fields that need special 'numeric' sorting. This allows sorting of numbers
     * in strings, so that
     *
     * 1-document.txt
     * 2-document.txt
     * 11-document.txt
     *
     * are sorted in their correct order, and the '11' document doesn't come immediately
     * after the '1' document.
     *
     */
    protected function getNumericSortFields()
    {
        if ($this->NumericSort) {
            return $this->NumericSort->getValue();
        }
        return array();
    }


    /**
     * Get a list of columns that should have subsequent duplicated entries 'blanked' out
     *
     * This is used in cases where there is a table of data that might have 3 different values in
     * the left column, and for each of those 3 values, many entries in the right column. What will happen
     * (if the array here returns 'LeftColFieldName') is that any immediately following column that
     * has the same value as current is blanked out.
     */
    public function getDuplicatedBlankingFields()
    {
        if ($this->ClearColumns && $this->ClearColumns->getValues()) {
            $fields = $this->ClearColumns->getValues();
            $ret = array();
            foreach ($fields as $field) {
                if (strpos($field, '.')) {
                    $field = $this->dottedFieldToUnique($field);
                }
                $ret[] = $field;
            }
            return $ret;
        }
        return array();
    }

    /**
     * Gets field formatting functions used for applying transformations to values.
     *
     * The formatters should be a map of field name to callable. The callable
     * is passed the original value and current record.
     *
     * @return array
     */
    public function getFieldFormatting()
    {
        $combined_array = array();

        // make sure we dont try to combine are arrays and have at least 1 element
        $keys = $this->FieldFormattingField->getValues();
        $values = $this->FieldFormattingFormatter->getValues();

        if (is_array($keys) && is_array($values) && count($keys) == count($values) && count($keys) > 0) {
            $combined_array = array_combine($keys, $values);
        }

        return $combined_array;
    }

    /**
     * Creates a report in a specified format, returning a string which contains either
     * the raw content of the report, or an object that encapsulates the report (eg a PDF).
     *
     * @param string $format
     * @param boolean $store
     *				Whether to store the created report.
     * @param array $parameters
     *				An array of parameters that will be used as dynamic replacements
     */
    public function createReport($format = 'html', $store = false)
    {
        Requirements::clear();
        $convertTo = null;
        $renderFormat = $format;
        $conversions = $this->config()->conversion_formats;

        if (isset($conversions[$format])) {
            $convertTo = 'pdf';
            $renderFormat = $conversions[$format];
        }

        $formatter = $this->getReportFormatter($renderFormat);

        if ($formatter) {
            $content = $formatter->format();
        } else {
            $content = "Formatter for '$renderFormat' not found.";
        }

        $classes = array_reverse(ClassInfo::ancestry(get_class($this)));
        $templates = array();
        foreach ($classes as $cls) {
            if ($cls == AdvancedReport::class) {
                // catchall
                $templates[] = 'AdvancedReport_' . $renderFormat;
                break;
            }
            $templates[] = $cls . '_' . $renderFormat;
        }

        $date = DBField::create_field(DBDatetime::class, time());
        $this->Text = nl2br($this->Text);

        $reportData = array('ReportContent' => $content, 'Format' => $format, 'Now' => $date);
        $additionalData = $this->additionalReportData();
        $reportData = array_merge($reportData, $additionalData);

        $output = $this->customise($reportData)->renderWith($templates);
        if ($output instanceof DBHTMLText) {
            $output = $output->getValue();
        }

        if (!$output) {
            // put_contents fails if it's an empty string...
            $output = " ";
        }

        if (!$convertTo) {
            if ($store) {
                // stick it in a temp file?
                $outputFile = tempnam(TEMP_FOLDER, $format);
                if (file_put_contents($outputFile, $output)) {
                    return new AdvancedReportOutput(null, $outputFile);
                } else {
                    throw new Exception("Failed creating report in $outputFile");
                }
            } else {
                return new AdvancedReportOutput($output);
            }
        }

        // hard coded for now, need proper content transformations....
        switch ($convertTo) {
            case 'pdf': {
                    if ($store) {
                        $filename = singleton('PdfRenditionService')->render($output);
                        return new AdvancedReportOutput(null, $filename);
                    } else {
                        singleton('PdfRenditionService')->render($output, 'browser');
                        return new AdvancedReportOutput();
                    }
                    break;
                }
            default: {
                    break;
                }
        }
    }

    /**
     * Get an array of additional data to add to a report.
     *
     * @return array
     */
    protected function additionalReportData()
    {
        return array();
    }

    /**
     * Generates an actual report file.
     *
     * @param string $format
     */
    public function generateReport($format = 'html')
    {
        $field = strtoupper($format) . 'FileID';
        $storeIn = $this->getReportFolder();

        // SS hates spaces in here :(
        $name = preg_replace('/ +/', '-', trim($this->Title));
        $name = $name . '.' . $format;
        $name = FileNameFilter::create()->filter($name);

        $childId = $storeIn->constructChild($name);
        $file = DataObject::get_by_id(File::class, $childId);

        // it's a new file, so trigger the onAfterUpload method for extensions that expect it
        if (method_exists($file, 'onAfterUpload')) {
            $file->onAfterUpload();
        }

        // okay, now we should copy across... right?
        $file->setName($name);
        $file->write();

        // create the raw report file
        $output = $this->createReport($format, true);

        if (is_object($output)) {
            if (file_exists($output->filename)) {
                if ($this->config()->store_file_content) {
                    $file->Content = base64_encode(file_get_contents($output->filename));
                    $file->write();
                } else {
                    copy($output->filename, $file->getFullPath());
                }
            }
        }

        // make sure to set the appropriate ID
        $this->$field = $file->ID;
        $this->write();
    }

    /**
     * Returns a report formatter instance for an output format.
     *
     * @param string $format
     * @return ReportFormatter
     */
    public function getReportFormatter($format)
    {
        $class = ucfirst($format) . ReportFormatter::class;

        if (class_exists($class)) {
            return new $class($this);
        }
    }

    /**
     * Gets the report folder used for storing generated reports.
     *
     * @return Folder|null
     */
    protected function getReportFolder()
    {
        return Folder::find_or_make("advanced-reports/$this->ReportID/$this->ID");
    }

    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_AdvancedReportsAdmin', 'any', $member);
    }

    public function canEdit($member = null)
    {
        return Permission::check('EDIT_ADVANCED_REPORT', 'any', $member);
    }

    public function canDelete($member = null)
    {
        return Permission::check('CMS_ACCESS_AdvancedReportsAdmin', 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        return Permission::check('CMS_ACCESS_AdvancedReportsAdmin', 'any', $member);
    }

    public function canGenerate($member = null)
    {
        return Permission::check('GENERATE_ADVANCED_REPORT', 'any', $member);
    }

    /**
     * @return array
     */
    public function providePermissions()
    {
        return array(
            'EDIT_ADVANCED_REPORT' => array(
                'name' => _t('AdvancedReport.EDIT', 'Create and edit Advanced Report pages'),
                'category' => _t('AdvancedReport.ADVANCED_REPORTS_CATEGORY', 'Advanced Reports permissions'),
                'help' => _t(
                    'AdvancedReport.ADVANCED_REPORTS_EDIT_HELP',
                    'Users with this permission can create new Report Pages from a Report Holder page'
                ),
                'sort' => 400
            ),
            'GENERATE_ADVANCED_REPORT' => array(
                'name' => _t('AdvancedReport.GENERATE', 'Generate an Advanced Report'),
                'category' => _t('AdvancedReport.ADVANCED_REPORTS_CATEGORY', 'Advanced Reports permissions'),
                'help' => _t(
                    'AdvancedReport.ADVANCED_REPORTS_GENERATE_HELP',
                    'Users with this permission can generate reports based on ' .
                        'existing report templates via a frontend Report Page'
                ),
                'sort' => 400
            ),
        );
    }

    public function dbQuote($filter = array(), $join = " AND ")
    {
        $QUOTE_CHAR = defined('DB::USE_ANSI_SQL') ? '"' : '';

        $string = '';
        $sep = '';

        foreach ($filter as $field => $value) {
            // first break the field up into its two components
            $operator = '';
            if (is_string($field)) {
                list($field, $operator) = explode(' ', trim($field));
            }

            if (is_array($value) && $operator == '=') {
                // convert to "IN"
                $operator = 'IN';
            }

            $value = $this->recursiveQuote($value);

            // not using quote char if it's already escaped
            if ($field[0] == '"') {
                $QUOTE_CHAR = '';
            } else {
                $QUOTE_CHAR = defined('DB::USE_ANSI_SQL') ? '"' : '';
            }

            if (strpos($field, '.')) {
                list($tb, $fl) = explode('.', $field);
                $string .= $sep . $QUOTE_CHAR . $tb . $QUOTE_CHAR . '.' . $QUOTE_CHAR . $fl . $QUOTE_CHAR
                    . " $operator " . $value;
            } else {
                if (is_numeric($field)) {
                    $string .= $sep . $value;
                } else {
                    $string .= $sep . $QUOTE_CHAR . $field . $QUOTE_CHAR . " $operator " . $value;
                }
            }

            $sep = $join;
        }

        return $string;
    }

    protected function recursiveQuote($val)
    {
        if (is_array($val)) {
            $return = array();
            foreach ($val as $v) {
                $return[] = $this->recursiveQuote($v);
            }

            return '(' . implode(',', $return) . ')';
        } else if (is_null($val)) {
            $val = 'NULL';
        } else if (is_int($val)) {
            $val = (int) $val;
        } else if (is_double($val)) {
            $val = (float) $val;
        } else if (is_float($val)) {
            $val = (float) $val;
        } else {
            $val = "'" . Convert::raw2sql($val) . "'";
        }

        return $val;
    }
}

class ConditionFilters
{

    const ARGUMENT_SEPARATOR = '|';

    protected $possibleParamValues = array();

    public function __construct($possibleValues = array())
    {
        $this->possibleParamValues = $possibleValues;
    }

    public function strtotimeDateValue($value)
    {
        $args = $this->getArgs($value);
        if (!isset($args[1])) {
            $args[1] = 'Y-m-d H:i:s';
        }
        if ($args[1] == 'stamp') {
            return strtotime($args[0]);
        }
        return date($args[1], strtotime($args[0]));
    }

    public function paramValue($value, $report)
    {
        $args = $this->getArgs($value);
        $params = $report->ReportParams;
        if ($params) {
            $params = $params->getValues();
        }

        if (isset($_GET[$args[0]])) {
            return $_GET[$args[0]];
        }

        if ($params && isset($args[0]) && isset($params[$args[0]])) {
            return $report->applyFiltersToValue($params[$args[0]]);
        }


        return '';
    }

    protected function getArgs($str)
    {
        return explode(self::ARGUMENT_SEPARATOR, $str);
    }
}

/**
 * Wrapper around a report output that might be raw content or a filename to the
 * report
 *
 */
class AdvancedReportOutput
{
    public $filename;
    public $content;

    public function __construct($content = null, $filename = null)
    {
        $this->filename = $filename;
        $this->content = $content;
    }
}
