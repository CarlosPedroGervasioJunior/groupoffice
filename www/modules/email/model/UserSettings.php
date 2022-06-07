<?php
namespace GO\Email\Model;

use GO\Base\Db\FindParams;
use GO\Base\Model\Template;
use go\core\orm\Mapping;
use go\core\orm\Property;
use function GO;


/**
 * Temporary workaround for saving old settings form a user property;
 */
class UserSettings extends Property {
	public $id;
	public $use_desktop_composer;
	public $use_html_markup;
	public $show_from;
	public $show_cc;
	public $show_bcc;
	public $skip_unknown_recipients;
	public $always_request_notification;
	public $always_respond_to_notifications;
	public $font_size;
	public $sort_email_addresses_by_time;
	public $defaultTemplateId;
	
	protected static function defineMapping(): Mapping
	{
		return parent::defineMapping()->addTable('core_user');
	}
	
	protected function init() {
		parent::init();

		$dt = DefaultTemplate::model()->findByPk($this->id);
		if($dt) {
			$this->defaultTemplateId = (int) $dt->template_id;
		} else{
			$findParams = FindParams::newInstance()->limit(1);
			$stmt = Template::model()->find($findParams);

			if($template=$stmt->fetch()) {
				$this->defaultTemplateId = (int) $template->id;
			}
		}

		$this->use_desktop_composer = !!\GO::config()->get_setting("use_desktop_composer", $this->id);
		$this->use_html_markup = !\GO::config()->get_setting("email_use_plain_text_markup", $this->id);
		$this->show_from = !!\GO::config()->get_setting("email_show_from", $this->id, 1);
		$this->show_cc = !!\GO::config()->get_setting("email_show_cc", $this->id, 1);
		$this->show_bcc = !!\GO::config()->get_setting("email_show_bcc", $this->id, 0);
		$this->skip_unknown_recipients = !!\GO::config()->get_setting("email_skip_unknown_recipients", $this->id);
		$this->always_request_notification = !!\GO::config()->get_setting("email_always_request_notification", $this->id);
		$this->always_respond_to_notifications = !!\GO::config()->get_setting("email_always_respond_to_notifications", $this->id);
		$this->font_size = \GO::config()->get_setting("email_font_size", $this->id);
		if(!$this->font_size) {
			$this->font_size = "14px";
		}

		$this->sort_email_addresses_by_time = !!\GO::config()->get_setting("email_sort_email_addresses_by_time", $this->id, true);

		//hackish way to make prop unmodified after this population so it won't think it's modified.
		$this->commit();
	}
	
	
	
	protected function internalSave(): bool
	{
		if($this->isModified('defaultTemplateId')) {
			$dt = DefaultTemplate::model()->findByPk($this->id);
			if(!$dt) {
				$dt = new DefaultTemplate();
				$dt->user_id = $this->id;
			}
			if(!empty($this->defaultTemplateId)) {
				$dt->template_id = $this->defaultTemplateId;
				$dt->save();
			} else if(!$dt->isNew()){
				$dt->delete();
			}
		}

		\GO::config()->save_setting('use_desktop_composer', !empty($this->use_desktop_composer) ? '1' : '0', $this->id);
		\GO::config()->save_setting('email_use_plain_text_markup', !empty($this->use_html_markup) ? '0' : '1', $this->id);
		\GO::config()->save_setting('email_show_from', !empty($this->show_from) ? 1 : 0, $this->id);
		\GO::config()->save_setting('email_show_cc', !empty($this->show_cc) ? 1 : 0, $this->id);
		\GO::config()->save_setting('email_show_bcc', !empty($this->show_bcc) ? 1 : 0, $this->id);
		\GO::config()->save_setting('email_skip_unknown_recipients', !empty($this->skip_unknown_recipients) ? '1' : '0', $this->id);
		\GO::config()->save_setting('email_always_request_notification', !empty($this->always_request_notification) ? '1' : '0', $this->id);
		\GO::config()->save_setting('email_always_respond_to_notifications', !empty($this->always_respond_to_notifications) ? '1' : '0', $this->id);
		\GO::config()->save_setting('email_sort_email_addresses_by_time', !empty($this->sort_email_addresses_by_time) ? '1' : '0', $this->id);
		\GO::config()->save_setting('email_font_size', $this->font_size, $this->id);

		return true;
	}
}
