<?php

namespace SilverstripeAustralia\AdvancedReports\Admin;

use SilverstripeAustralia\AdvancedReports\Models\AdvancedReport;
use SilverStripe\Forms\FormAction;
use SilverStripe\Assets\File;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;


/**
 * Handles requests for managing individual advanced report instances.
 */
class AdvancedReportsAdminItemRequest extends GridFieldDetailForm_ItemRequest
{

    private static $allowed_actions = array(
        'ItemEditForm',
        'viewreport',
    );

    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();

        if ($this->record->isInDB() && $this->record->canGenerate()) {
            $form->Actions()->merge(array(
                FormAction::create('reportpreview', '')
                    ->setTitle(_t('AdvancedReport.PREVIEW', 'Preview'))
                    ->setAttribute('target', '_blank')
                    ->setAttribute('data-icon', 'preview'),
                FormAction::create('generate', '')
                    ->setTitle(_t('AdvancedReport.GENERATE', 'Generate'))
            ));
        }

        return $form;
    }

    /**
     * Handler to view a generated report file
     *
     * @param type $data
     * @param type $form
     */
    public function viewreport($request)
    {
        $allowed = array('html', 'pdf', 'csv');
        $ext = $request->getExtension();
        if (!in_array($ext, $allowed)) {
            return $this->httpError(404);
        }
        $reportID = (int) $request->param('ID');

        $report = AdvancedReport::get()->byID($reportID);
        if (!$report || !$report->canView()) {
            return $this->httpError(404);
        }
        $file = $report->{strtoupper($ext) . File::class}();
        if (!$file || !strlen($file->Content)) {
            return $this->httpError(404);
        }

        $mimeType = HTTP::get_mime_type($file->Name);
        header("Content-Type: {$mimeType}; name=\"" . addslashes($file->Name) . "\"");
        header("Content-Disposition: attachment; filename=" . addslashes($file->Name));
        header("Content-Length: {$file->getSize()}");
        header("Pragma: ");

        session_write_close();
        ob_flush();
        flush();
        // Push the file while not EOF and connection exists
        echo base64_decode($file->Content);
        exit();
    }

    public function reportpreview($data, $form)
    {
        $formData = $form->getData();
        $format = $formData['PreviewFormat'];

        $result = $this->record->createReport($format);

        if ($result->content) {
            return $result->content;
        } else {
            return HTTPRequest::send_file(
                file_get_contents($result->filename),
                "$formData[GeneratedReportTitle].$format"
            );
        }
    }

    public function generate($data, $form)
    {
        $report = $this->record;

        if (!empty($data['GeneratedReportTitle'])) {
            $title = $data['GeneratedReportTitle'];
        } else {
            $title = $report->Title;
        }

        $report->GeneratedReportTitle = $title;
        $report->prepareAndGenerate();

        return Controller::curr()->redirect($this->Link());
    }
}
