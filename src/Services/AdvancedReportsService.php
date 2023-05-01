<?php

namespace SilverstripeAustralia\AdvancedReports\Services;

use SilverStripe\Core\ClassInfo;
use SilverstripeAustralia\AdvancedReports\Models\AdvancedReport;

/**
 * The default implementation of the advanced reports service.
 */
class AdvancedReportsService implements AdvancedReportsServiceInterface
{

    public function getReportTypes()
    {
        $classes = ClassInfo::subclassesFor(AdvancedReport::class);
        $result = array();

        array_shift($classes);

        foreach ($classes as $class) {
            $result[$class] = singleton($class)->singular_name();
        }

        return $result;
    }
}
