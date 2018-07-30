<?php

namespace Mateusz\Polls;

use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Assets\Image;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Tabset;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;

/**
 * This represents a poll data object that should have 2 more {@link PollChoice}s
 * 
 * @package polls
 */
class Poll extends DataObject implements PermissionProvider {

	const COOKIE_PREFIX = 'SSPoll_';

	private static $table_name = 'Poll';
	
	private static $db = Array(
		'Title' => 'Varchar(100)',
		'Description' => 'HTMLText',
		'IsActive' => 'Boolean(1)',
		'MultiChoice' => 'Boolean',
		'Embargo' => 'Datetime',
		'Expiry' => 'Datetime'
	);

	private static $has_one = array(
		'Image' => Image::class
	);
	
	private static $has_many = array(
		'Choices' => PollChoice::class
	);
	
	private static $searchable_fields = array(
		'Title', 
		'IsActive'
	);
	
	private static $summary_fields = array(
		'Title',
		'IsActive',
		'Embargo',
		'Expiry'
	); 
	
	private static $default_sort = 'Created DESC';

	private static $vote_handler_class = '\Mateusz\Polls\CookieVoteHandler';

	public $voteHandler;

	public function __construct($record = null, $isSingleton = false, $model = null) {
		parent::__construct($record, $isSingleton, $model);
		//create the voteHandler
		$this->voteHandler = Injector::inst()->create($this->config()->get('vote_handler_class'), $this);
	}

	public function getCMSFields() {

		if($this->ID != 0) {
			$totalCount = $this->getTotalVotes();
		}
		else {
			$totalCount = 0;
		}
		
		$fields = FieldList::create(
			$rootTab = TabSet::create("Root",
				Tab::create("Main",
					TextField::create('Title', _t('Poll.TITLE', 'Poll title'), null, 100)
						->setRightTitle(_t('Poll.MAXCHARACTERS', 'Maximum 100 characters')),
					OptionsetField::create('MultiChoice',
						_t('Poll.ANSWERTYPE', 'Answer type'),
						array(
							0 => 'Single',
							1 => 'Multi-choice'
						)
					)->setRightTitle(_t('Poll.ANSWERTYPEDESCRIPTION', '"Single" uses radio buttons, "Multi-choice" uses tick boxes')),
					OptionsetField::create('IsActive', _t('Poll.STATE', 'Poll state'), array(1 => 'Active', 0 => 'Inactive')),
					$embargo = DatetimeField::create('Embargo', _t('Poll.EMBARGO', 'Embargo')),
					$expiry = DatetimeField::create('Expiry', _t('Poll.EXPIRY', 'Expiry')),
					HTMLEditorField::create('Description', _t('Poll.DESCRIPTION', 'Description')),
					$image = UploadField::create('Image', _t('Poll.IMAGE', 'Poll image'))
				)
			)
		);

		// Add the fields that depend on the poll being already saved and having an ID 
		if($this->ID != 0) {

			$config = GridFieldConfig::create();
			$config->addComponent(new GridFieldToolbarHeader());
			$config->addComponent(new GridFieldAddNewButton('toolbar-header-right'));
			$config->addComponent(new GridFieldDataColumns());
			$config->addComponent(new GridFieldEditButton());
			$config->addComponent(new GridFieldDeleteAction());
			$config->addComponent(new GridFieldDetailForm());
			$config->addComponent(new GridFieldSortableHeader());

			if (class_exists('GridFieldOrderableRows')){
				$config->addComponent(new GridFieldOrderableRows('Order'));
			}

			$pollChoicesTable = GridField::create(
				'Choices',
				_t('Poll.CHOICES', 'Choices'),
				$this->Choices(),
				$config
			);

			$fields->addFieldToTab('Root.Data', $pollChoicesTable);

			$fields->addFieldToTab('Root.Data', ReadonlyField::create('Total', _t('Poll.TOTALVOTES', 'Total votes'), $totalCount));
			
			// Display the results using the default poll chart
			$pollForm = PollForm::create(new Controller(), 'PollForm', $this);
			$chartTab = Tab::create("Result chart", LiteralField::create('Chart', sprintf(
				'<h1>%s</h1><p>%s</p>', 
				$this->Title, 
				$pollForm->getChart(), 
				$this->Title))
			);
			$rootTab->push($chartTab);
		}
		else {
			$fields->addFieldToTab('Root.Choices', ReadonlyField::create('ChoicesPlaceholder', 'Choices', 'You will be able to add options once you have saved the poll for the first time.'));
		}
				
		$this->extend('updateCMSFields', $fields);
		
		return $fields;
	}

	/**
	 * Get the most recently added Poll that can be visible
	 * 
	 * @return Poll|null A Poll if one is visible, null otherwise
	 */
	public static function get_current_poll(){
		$now = DBDatetime::now();
		$polls = Poll::get()
			->filter('IsActive', "1")
			->where('"Embargo" IS NULL OR "Embargo" < \'' . $now . "'")
			->where('"Expiry" IS NULL OR "Expiry" > \'' . $now . "'");

		return $polls->Count() ? $polls->First() : null;
	}

	function getTotalVotes() {
		return $this->Choices()->sum('Votes');
	}

	function getMaxVotes() {
		return $this->Choices()->max('Votes');
	}
	
	/**
	 * Mark the the poll has been voted by the user, which determined by browser cookie
	 */
	function markAsVoted() {
		return $this->voteHandler->markAsVoted();
	}
	
	/**
	 * Check if the user, determined by browser cookie, has been submitted a vote to the poll.
	 *
	 * @param integer
	 * @return bool 
	 */
	function hasVoted() {
		return $this->voteHandler->hasVoted();
	}

	/**
	 * Check if poll should be visible, taking into account the IsActive and embargo/expiry
	 */
	function getVisible() {
		if (!$this->IsActive) return false;
		
		if ($this->Embargo && DBDatetime::now()->Format('U')<$this->obj('Embargo')->Format('U') || 
			$this->Expiry && DBDatetime::now()->Format('U')>$this->obj('Expiry')->Format('U')) {
			return false;
		}
		
		return true;
	}
	
	function providePermissions(){
        return array(
            "MANAGE_POLLS" => "Manage Polls",
        );
    }
    
	public function canCreate($member = null, $context = []) {
		return Permission::check('MANAGE_POLLS', 'any', $member);
	}
	
	public function canEdit($member = null, $context = []) {
		return Permission::check('MANAGE_POLLS', 'any', $member);
	}
	
	public function canDelete($member = null, $context = []) {
		return Permission::check('MANAGE_POLLS', 'any', $member);
	}

	public function canVote() {
		return $this->voteHandler->canVote();
	}
}
