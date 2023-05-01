<?php

namespace SilverstripeAustralia\AdvancedReports\Pages;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverstripeAustralia\AdvancedReports\Pages\ReportPage;
use SilverstripeAustralia\AdvancedReports\Models\AdvancedReport;

/**
 * Lists and allows creating reports in the front end.
 */
class ReportHolder extends SiteTree
{

    private static $allowed_children = array(
        ReportPage::class,
    );
}

class ReportHolderController extends ContentController
{

    private static $allowed_actions = array(
        'Form'
    );

    public function Form()
    {
        if (!singleton(AdvancedReport::class)->canCreate()) {
            return null;
        }

        $classes = ClassInfo::subclassesFor(AdvancedReport::class);
        $titles = array();

        array_shift($classes);

        foreach ($classes as $class) {
            $titles[$class] = singleton($class)->singular_name();
        }

        return new Form(
            $this,
            Form::class,
            new FieldList(
                new TextField('Title', _t('ReportHolder.TITLE', 'Title')),
                new TextareaField('Description', _t('ReportHolder.DESCRIPTION', 'Description')),
                DropdownField::create('ClassName')
                    ->setTitle(_t('ReportHolder.TYPE', 'Type'))
                    ->setSource($titles)
                    ->setHasEmptyDefault(true)
            ),
            new FieldList(
                new FormAction('doCreate', _t('ReportHolder.CREATE', 'Create'))
            ),
            new RequiredFields('Title', 'ClassName')
        );
    }

    public function doCreate($data, $form)
    {
        if (!singleton(AdvancedReport::class)->canCreate()) {
            return Security::permissionFailure($this);
        }

        $formData = $form->getData();

        $description = $formData['Description'];
        $class = $formData['ClassName'];

        if (!is_subclass_of($class, AdvancedReport::class)) {
            $form->addErrorMessage(
                'ClassName',
                _t('ReportHolder.INVALID_TYPE', 'An invalid report type was selected'),
                'required'
            );

            return $this->redirectBack();
        }

        $page = new ReportPage();

        $page->update(array(
            'Title' => $formData['Title'],
            'Content' => $description ? "<p>$description</p>" : '',
            'ReportType' => $class,
            'ParentID' => $this->data()->ID
        ));

        $page->writeToStage('Stage');

        if (Versioned::get_stage() == Versioned::LIVE) {
            $page->doPublish();
        }

        return $this->redirect($page->Link());
    }
}
