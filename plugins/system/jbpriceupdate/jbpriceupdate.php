<?php

/**
* @package   JBPriceUpdate Plugin for JBZoo CCK package
* @author    KVV
* @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
*/

// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

jimport('joomla.plugin.plugin');
jimport('joomla.filesystem.file');
jimport('joomla.application.component.controller');
require_once(JPATH_ADMINISTRATOR . '/components/com_zoo/config.php');

use Joomla\Utilities\ArrayHelper;

class plgSystemJbpriceupdate extends JPlugin
{
	public $list_skipping = array();
	
	private $_sid;
	private $_pid;
	
	protected $_item;
	protected $_item_price_element;
	protected $_item_suppliersku_element;
	
	protected $_good;
	 
	protected $_json_data;
	protected $_json_dataSku;
	
	protected $_supplierApi;
	protected $_supplierMargin;
	protected $_supplierPrice;
	protected $_supplierIsQuantity;
	
	protected $_exchangeRate = 0;
	
	
	public function onAfterInitialise(){	
	}
	
	private function _json_decode_nice($json, $assoc = FALSE)
	{
		$json = str_replace(array("\n","\r","\t","\{","\}","\[","\]"),"",$json); //\\n
		//$json = preg_replace('/([{,]+)(\s*)([^"]+?)\s*:/','$1"$3":',$json);
		//$json = preg_replace('/(,)\s*}$/','}',$json);
		try
		{
			return json_decode($json,$assoc);
		}
		catch (JsonException $E)
		{
			echo new JResponseJson(500,NULL,FALSE,TRUE);
			throw new supplierException($E);
		}
	}
	
	public function onAfterRoute(){
		
		$this->app = JFactory::getApplication();
		$this->zoo = App::getInstance('zoo');
		$input = JFactory::getApplication()->input;
		$sid = $input->get('sid',NULL);
		$pid = $input->get('pid',NULL);
		$task = $input->get('task',null);
		
		if (!empty($sid)) {
			$this->_sid = $this->params->get('jbzoo_ssid');
			
			if ($this->_sid == $sid) {
				if (empty($task)) {
					$fdata = file_get_contents('php://input');
					$this->_json_data 	= $this->_json_decode_nice($fdata); 
					
					if (is_null($this->_json_data)) {
						echo new JResponseJson(500,NULL,FALSE,TRUE);
						exit();
					}

					if ($this->_sid == $this->_json_data->sid) {
						$this->_updateItemJson();
					}
				}else{
					switch ($task) {
						case 'metaadd':
							$catId = $input->get('catId',null);
							$metaData = $input->get('metakey',null,'STRING');
							$this->_addItemsMetadata($catId,$metaData);
						case 'metaremove':
							$catId = $input->get('catId',null);
							$metaData = $input->get('metakey',null,'STRING');
							$this->_removeItemsMetadata($catId,$metaData);
						case 'unpublish':

						default:
							jexit();
					}
				}
			}
		}elseif (!empty($pid)) {
			$this->_pid = $this->params->get('jbzoo_spid');
			if ($this->_pid == $pid) {
				$filter['created_from'] = $input->get('dateFrom',NULL);
				$filter['created_to'] = $input->get('dateTo',NULL);

				$this->_getListOrders($filter);
			}
		}
	}
	
	/**
	* Добавляет в конец 'metadata.keywords' переданную строку
	 * 
	 * @param array|string $listCatId
	 * @param string $metaData
	 * 
	 * @return void
	 */
	private function _addItemsMetadata($listCatId = null, $metaData = null)
	{
		if (!empty($metaData)) {
			$appId = (array)explode(",", $this->params->get('jbzoo_appId'));
			if (!empty($listCatId)) {
				$catId = (array)explode(".", $listCatId);
			} else {
				$catId = (array)explode(",", $this->params->get('jbzoo_catId'));
			}

			$listItem = JBModelItem::model()->getList($appId,$catId);

			foreach ($listItem as $item) {
				if (isset($item)) {
					$this->_item = $item;

					$currentMetaData = (string)$this->_item->getParams()->get('metadata.keywords', '');
					$currentMetaData = (string)str_replace(",,",",",$currentMetaData); //temporary
					$currentMetaData = (string)str_replace(", ,",",",$currentMetaData); //temporary
					$metaData = htmlspecialchars($metaData);
					if (!strpos($currentMetaData,(string)$metaData)) {
						$this->_item->getParams()->set('metadata.keywords', $currentMetaData.', '.$metaData);
						$this->zoo->table->item->save($this->_item);
					}
				}
			}
			jexit('OK');
		}else{
			jexit('"metadata.keywords" is empty');
		}
	}
	
	/**
	* Удаляет из 'metadata.keywords' переданную строку
	* 
	* @param undefined $listCatId
	* @param undefined $metaData
	* 
	* @return
	*/
	private function _removeItemsMetadata($listCatId = null, $metaData = null){
		if (!empty($metaData)) {
			$appId = (array)explode(",", $this->params->get('jbzoo_appId'));
			if (!empty($listCatId)) {
				$catId = (array)explode(".", $listCatId);
			} else {
				$catId = (array)explode(",", $this->params->get('jbzoo_catId'));
			}

			$listItem = JBModelItem::model()->getList($appId,$catId);

			foreach ($listItem as $item) {
				if (isset($item)) {
					$this->_item = $item;

					$currentMetaData = (string)$this->_item->getParams()->get('metadata.keywords', '');
					$currentMetaData = (string)str_replace(",,",",",$currentMetaData);
					$currentMetaData = (string)str_replace(", ,",",",$currentMetaData);
					if (!strpos(strtolower($currentMetaData),(string)$metaData)) {
						$this->_item->getParams()->set('metadata.keywords', (string)str_replace($metaData,"",$currentMetaData));
						$this->zoo->table->item->save($this->_item);
					}
				}
			}
			jexit('OK');
		} else {
			jexit('"metadata.keywords" is empty');
		}
	}
	
	/**
	* Обновление материала (товара) по переданному JSON-массиву данных
	* 
	* @return JSON
	*/
	private function _updateItemJson()
	{
		$count_skip = 0;
		$goods = $this->_json_data->goods;
		foreach ($goods as $num => $good) {
			$this->_good = $good;
			$sku = $good->_sku;
			
			if (!empty($sku)) {
				$item = JBModelItem::model()->getBySku($sku);
				
				if (isset($item)) {
					$this->_item = $item;
					
					if (isset($good->name)) {
						$this->_item->name = $good->name;
						$this->_item->alias = $this->zoo->string->sluggify($good->name);
						$this->_item->alias = $this->zoo->alias->item->getUniqueAlias($this->_item->id, $this->_item->alias);
					}
					
					if (isset($good->_itemstate))
					{
						$this->_item->state = $good->_itemstate;
					}
					
					if (isset($good->category_primary)) {
						$appCategories = $this->_item->getApplication()->getCategories();

						$appCategoryNames = array_map(function ($cat){
							return $cat->name;
						}, $appCategories);

						$primaryCategoryId = null;
						
						if ($good->category_primary && $id = array_search($good->category_primary, $appCategoryNames)) {
							$primaryCategoryId = $id;
						}
						if (!is_null($primaryCategoryId)) {
							$relatedCategories[0] = $primaryCategoryId;

							$this->zoo->category->saveCategoryItemRelations($this->_item, $relatedCategories);

							$this->_item->params['config.primary_category'] = $primaryCategoryId;
						}
					}
								
					$list_prices = $this->zoo->jbprice->getItemPrices($this->_item);
					
					foreach ($list_prices as $key_price => $el_price) {
						$this->_item_price_element = $this->_item->getElement($key_price);
						$price_data = (array)$this->_item_price_element->data();
						
						if (isset($good->_value)) {
							$price_data['variations']['0']['_value']['value'] = $good->_value;
						}
						if (isset($good->_discount)) {
							$price_data['variations']['0']['_discount']['value'] = $good->_discount;
						}
						if (isset($good->_balance)) {
							$price_data['variations']['0']['_balance']['value'] = $good->_balance;
						}
						
						$this->_item_price_element->bindData($price_data);
						$this->zoo->table->item->save($this->_item);
					}
				}
 				else 
 				{
					 $this->list_skipping[$count_skip] = $sku;
					 $count_skip++;
				}
			}
		}

		echo new JResponseJson($this->list_skipping,NULL,FALSE,TRUE);
		jexit();
	}
	
	/**
	* Получает список заказов интернет-магазина
	* 
	* @return JSON
	*/
	private function _getListOrders($filter)
	{
		$orders = JBModelOrder::model()->getList($filter);
		
		$customerNameElementId 		= $this->params->get('customerName_ID');
		$customerINNElementId		= $this->params->get('customerINN_ID');
		$customerKPPElementId		= $this->params->get('customerKPP_ID');
		$customerAddressElementId	= $this->params->get('customerAddress_ID');
		
		foreach ($orders as $order) {
			$shipping 		= $order->getShipping();
			$shippingfields = $order->getShippingFields();
			$payment		= $order->getPayment();
			$payment_status = $order->getPaymentStatus();
			
			$orderData[$order->id]['created'] 			= $order->created;
			$orderData[$order->id]['status'] 			= $order->getStatus()->config->get('name');
			$orderData[$order->id]['total']				= $order->getTotalSum()->noStyle();
			$orderData[$order->id]['payment']			= JText::_($payment->getName());
			$orderData[$order->id]['payment_status']	= $payment_status->config->get('name');

			if ($payment->identifier == $order->getPaymentElement($this->params->get('paymentTypeId'))->identifier)
			{
				$orderData[$order->id]['customer']['name']		= $order->getFieldElement($customerNameElementId)->get('value','');
				$orderData[$order->id]['customer']['INN']		= $order->getFieldElement($customerINNElementId)->get('value','');
				$orderData[$order->id]['customer']['KPP']		= $order->getFieldElement($customerKPPElementId)->get('value','');
				$orderData[$order->id]['customer']['uraddress']	= $order->getFieldElement($customerAddressElementId)->get('value','');
			}
			
			$orderData[$order->id]['shipping']['type']	= JText::_($shipping->config->get('name'));
			$orderData[$order->id]['shipping']['price'] = $shipping->getRate()->noStyle();
			foreach ($shippingfields as $field_key => $shippingfield) {
				$orderData[$order->id]['shipping']['fields'][]['address'] = $shippingfield['value'];
			}
			
			$modifiersOrder = $order->getModifiersOrderPrice();
			$modifiersItem = $order->getModifiersItemPrice();
			
			if (is_array($modifiersOrder)) {
				foreach ($modifiersOrder as $identifier=>$modifier) {
					$groupModifier = $modifier->config->get('group');
					$typeModifier = $modifier->config->get('type');
					$orderData[$order->id]['modifiers'][$groupModifier][$typeModifier]['rate'] 	= $modifier->getRate()->text();
				}
			}
					
			$items = $order->getItems();
			foreach ($items as $item) {
				$orderData[$order->id]['items'][$item->item_id]['sku'] 		= $item['elements']['_sku'];
				$orderData[$order->id]['items'][$item->item_id]['name'] 	= JText::_($item->item_name);
				if (isset($item['modifiers'])) {
					$orderData[$order->id]['items'][$item->item_id]['itemmodifiers'] = array();
					foreach ($item['modifiers'] as $itemModifier) {
						$orderData[$order->id]['items'][$item->item_id]['itemmodifiers'][]['rate'] = $itemModifier['rate'][0];
						$orderData[$order->id]['items'][$item->item_id]['itemmodifiers'][]['cur'] = $itemModifier['rate'][1];
					}
				}
				$orderData[$order->id]['items'][$item->item_id]['price'] 	= $item['elements']['_value'];
				$orderData[$order->id]['items'][$item->item_id]['discount']	= $order->val($item['elements']['_discount'])->noStyle();
				$orderData[$order->id]['items'][$item->item_id]['quantity'] = $item->quantity;
			}
		}
		
		$ordersJsonData = json_encode($orderData, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
		echo $ordersJsonData;
		
		//echo new JResponseJson($orderData,NULL,FALSE,TRUE);
		
		jexit();
	}
	
}
?>