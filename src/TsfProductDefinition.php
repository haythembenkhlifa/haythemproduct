<?php

namespace haythembenkhlifa\haythemproduct;

use Log;
use App\Lead;
use App\User;
use Exception;
use App\Client;
use App\JobLog;
use App\Product;
use App\Quotation;
use Carbon\Carbon;
use App\Application;
use App\AppConstants;
use App\Helpers\OTRHelper;
use Laravel\Nova\Resource;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\FormHandoffResponse;
use App\Products\TsfFormWizard;
use App\Exceptions\InitException;
use Laravel\Nova\Fields\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use App\Exceptions\CalculationException;

class TsfProductDefinition
{
    const NO_OFFER_VALUE = -9999;
    protected $preventNewBusinessReason = 'No additional information';

    protected $product = null;
    protected $application = null;
    protected $quotation = null;
    protected $calculator = null;

    //New setting to indicate if this product has an API that can be "verified" with a call
    public $can_verify_api = false;

    //Specify if quote should have a wizzard interface
    public $quoteWizard = false;

    //Specify if application should have a wizzard interface
    public $applicationWizard = false;


    //Tell us on what date we should base age caluclations - leave to null to just use current date
    protected $agecalcs_basedate_propname = null;

    //Override if sales agent identity is required for new business
    protected $requires_salesidentity = false;

    //Override if no quality check is required
    public static $requires_quality_check = true;


    //Override this is lead data keeps lead key in a different field
    protected $lead_key_field = 'lead_id';

    protected $application_display_name_field = 'mainmember_idnumber';


    protected $quotation_display_name_field = 'mainmember_idnumber';

    //Here we can define fields that has ot be present in lead
    protected $required_lead_fields = [

    ];

    //You can add field names here if you want to generate a gender from an RSA ID number
    protected $gender_from_id_fields = [

    ];

    //You can add field names her if you want "expanded" date info in the payload
    protected $expand_date_fields = [
        'lastAppCalculated'
    ];

    //You can add lookup fields for which you also want to store the display value here
    //We will then find the label and add in a field_displayLabel prop
    protected $dropdown_label_from_code = [

    ];


    //You can add idnumber key fields for roles where valid dobs
    protected $dob_from_id_fields = [

    ];

    //You can add idnumber key fields for roles where valid dobs
    protected $dob_from_id_array_fields = [

    ];

    //Here we can define field value mappings in the format
    // ourfieldname_value1 => mappedValue1
    protected $mapped_fields = [

    ];


    //Here we can define value only mappings in the format
    // ourvalue1 => mappedValue1
    //
    //This is usefull for global mappings like Yes No = Y,N or True,False = T,F
    protected $mapped_values = [

    ];


    /**
     * If your product can be captured without a lead, we would need to "auto create" a new
     * client from the data in your app. So to do that map the fields in the application (not quote - application)
     * so we can do this.
     * We will set up most obvious defaults please override if required
     *
     */
    protected $client_field_map = [

        "client_type" => "client_type",
        "id_type" => "id_type",

        "title" => "title",
        "first_name" => "first_name",
        "surname" => "surname",

    ];


    /**
     * If your product can be captured without a lead, we also allow some details to be captured when you start the quote
     * this array specifies hwo we map that info into lead data so that it gets applied inside the quote correctly
     *
     */
    protected $nolead_field_map = [

        "nolead_title" => "title",
        "nolead_firstnames" => "firstname",
        "nolead_surname" => "surname",
        "nolead_idnumber" => "idnumber",

    ];

    protected $newbusiness_required_user_properties = [
    ];


    //Override the format of your transform if required
    protected $transform_format = AppConstants::TRANSFORM_FORMAT_JSON;

    //We will assign this object to quote or application or alteration data
    private $object = false;

    protected $quoteOfferValue = Self::NO_OFFER_VALUE;
    protected $quoteOfferDescription = 'Quotation offer';

    protected $applicationOfferValue = Self::NO_OFFER_VALUE;
    protected $applicationOfferDescription = 'Application offer';

    public $nova_action = 'Unknown';
    public $nova_editing = false;
    public $nova_editMode = [];
    public $nova_url_parts = null;
    public $nova_resource = null;
    public $nova_url = '';

    public function setRequestMeta(Resource $novaResource, $request, Model $model, $pluralModelName) {
        /*
        if ($request instanceof ResourceDetailRequest) $this->nova_screen = 'detail';
        if ($request instanceof ResourceIndexRequest) $this->nova_screen = 'index';
        if ($request instanceof UpdateResourceRequest) $this->nova_screen = 'update';
        */
        $this->nova_resource = $novaResource;
        $modelId = optional($model)->id;
        $novaURL = $request->path();
        $this->nova_url = $request->path();
        //if ($request->isCreateOrAttachRequest()) $this->nova_action = 'create';
        $this->nova_editing = $request->editing;
        $this->nova_editMode = $request->editMode;
        if (Str::contains($novaURL, 'nova-api')) {
            $this->nova_url_parts = collect(explode('/',$novaURL));
            if (Str::contains($novaURL, $pluralModelName)) {
                if ($this->nova_url_parts->last() == $pluralModelName) $this->nova_action = AppConstants::NOVA_ACTION_INDEX;
                if ($this->nova_url_parts->last() == $modelId) $this->nova_action = AppConstants::NOVA_ACTION_DETAIL;
                if ($this->nova_editing) $this->nova_action = AppConstants::NOVA_ACTION_UPDATE;
                if ($this->nova_url_parts->contains('associatable')) $this->nova_action = AppConstants::NOVA_ACTION_LOOKUP;
            }
        }
        OTRHelper::objectDebug($this,"NOVA IS DOING[$this->nova_action]",'NOVA-ACTION');

    }
    public function keepApplication($value) {
        $this->application = $value;
    }

    public function keepQuotation($value) {
        $this->quotation = $value;
    }


    public function mapNoLeadFieldsToLeadData($fields) {
        Log::debug('777777777777 WILL map fields for no lead:');
        Log::debug(json_encode($fields));
        $mapped = [];
        foreach ($fields as $key => $value) {
            Log::debug("We received [$key] and value [$value]");
            $leadKeyName = Arr::get($this->nolead_field_map,$key,$key);
            Log::debug("That is mapped to [$leadKeyName] and value [$value]");
            $mapped[$leadKeyName] = $value;
        }
        return $mapped;

    }

    /**
     * Here we make sure someone actually calcuted an offer.
     * If still NO_OFFER_VALUE we will raise alarm to prevent save
     */

    /**
     * Use this to define some fields to request when user creates a new product without a lead
     *
     */

    public function noLeadActionFields() {
        return [
        ];
    }

    //=====================================================END OF CONFIG SECTION

    public function __construct(Product $product) {
        $this->product = $product;
        //Lets see if there is a calculation object
        $this->calculator = $this->product->getCalculation();
    }

    public static function afterAcceptStatus() {
       if (self::$requires_quality_check === true) {
           return AppConstants::STATE_AWAIT_QA;
       } else {
        return AppConstants::STATE_CAPTURE_COMPLETE;
       }
    }

    public static function afterQualityApproveStatus() {
        return AppConstants::STATE_CAPTURE_COMPLETE;
    }



    protected function assertQuotationOfferSet() {
       if ($this->quoteOfferValue === Self::NO_OFFER_VALUE) $this->stopCalculation('No offer value calculated for quotation.');
    }

    protected function assertApplicationOfferSet() {
        if ($this->applicationOfferValue === Self::NO_OFFER_VALUE) $this->stopCalculation('No offer value calculated for application.');
    }


    public function  getApplicationDisplayName() {
        return $this->objectValue($this->application_display_name_field, '');
    }

    public function  getQuotationDisplayName() {
        return $this->objectValue($this->quotation_display_name_field, '');
    }

    public function getProductDropdownOptions($code, $filter = null) {

        $dropdown = $this->product->dropdown($code);
        if ($dropdown) {
            return $dropdown->selectOptions($filter);
        }
        return [];

    }

    public function getProductDropdown($code, $filter = null) {

        return $this->product->dropdown($code);

    }

    public function findProductDropDownOption($dropdownCode, $optionCode) {
        $dropdown = $this->product->dropdown($dropdownCode);
        if ($dropdown) {
            return $dropdown->findByCode($optionCode);
        }
        return null;

    }

    public function findProductDropDownOptionText($dropdownCode, $optionCode) {
        $dropdownOption = $this->findProductDropDownOption($dropdownCode,$optionCode);
        if ($dropdownOption) {
            return Str::title($dropdownOption->display_label);
        }
        return $optionCode;

    }

    public function findProductDropDownOptionTextFromData($dropdownCode, $dataPropertyName, $defaultValue = '') {
        $optionCode = $this->objectValue($dataPropertyName,$defaultValue);
        return  Str::title($this->findProductDropDownOptionText($dropdownCode,$optionCode));

    }

    /**
     * Find a benefit rate linked to the product
     */
    public function getBenefitRate($quickCode, $age, $cover) {

        $benefit = $this->product->dropdown($code);
        if ($dropdown) {
            return $dropdown->selectOptions();
        }
        return [];

    }

    protected function stopInit($error) {
        //We could possibly trace for info and stats
        Log::error('Initilization error:' . $error);
        throw new InitException($error);
    }

    public function stopCalculation($error) {
        //We could possibly trace for info and stats
        Log::error('Calculation error:' . $error);
        throw new CalculationException($error);
    }

    protected function stopTransformation($error) {
        //We could possibly trace for info and stats
        Log::error('Transformation error:' . $error);
        //throw new TransformationException($error);
    }

    //todo:this method must be protected, will find generic wrapper later.
    public function setWorkingObject($someDataObject) {
        $this->object = $someDataObject;
    }

    protected function copyAllQuoteData(Quotation $quote, $withPrefix = '') {
        $this->setObjectValues($quote->data,$withPrefix);
    }

    protected function setObjectValues($arrayOfValues, $prefix = '') {
        foreach ($arrayOfValues as $key => $value) {
            $this->setObjectValue($prefix . $key, $value);
        }
    }

    protected function getWorkingObject() {
        return $this->object;
    }

    /**
     * Reads an attribute from the data array asociated with the quote or application.
     * Underneath the covers - it is assumed a call has been made to "setWorkingObject" to
     * specify the working array
     *
     * You should use this method to read "normal" non nested attributes. For repeating objects
     * (like children / beneficiaries etc), you should rather use the connvenience method:
     *
     * nestedObjectValue
     *
     * @param mixed $propertyName
     * @param string $defaultValue
     * @return mixed
     */
    public function objectValue($propertyName, $defaultValue = '') {
        $value = Arr::get($this->object,$propertyName,$defaultValue);
        if ($value == null) return $defaultValue;
        return $value;

    }

    protected function mappedObjectValue($propertyName, $defaultValue = '') {
       $unmappedValue = $this->objectValue($propertyName, $defaultValue);
       return Arr::get($this->mapped_values, $unmappedValue, $unmappedValue);
    }

    protected function setMappedValue($propertyName, $unmappedValue, $map = null) {
        Log::debug("Want to set mapped value [$propertyName] val[$unmappedValue]");
        if (!$map) $map = $this->mapped_values;
        if ($unmappedValue != '') {
        $mappedValue = Arr::get($map,$unmappedValue,$unmappedValue);
        Log::debug("Mapped value is ");
        Log::debug($mappedValue);
        } else {
            Log::debug('We have an empty unmapped value so lets keep empty');
            $mappedValue = '';
        }
        $this->setObjectValue($propertyName,$mappedValue);
    }

    protected function mappedObjectFieldValue($propertyName, $defaultValue = '') {
        $unmappedValue = $this->objectValue($propertyName, $defaultValue);
        return Arr::get($this->mapped_fields, $propertyName . '_' . $unmappedValue, $unmappedValue);
     }

    protected function setObjectValue($propertyName, $propertyValue) {
        $this->object[$propertyName] = $propertyValue;
    }

    /**
     * See more details on nestedObjectValue
     *
     * @param mixed $object
     * @param mixed $propertyName
     * @param string $defaultValue
     * @return mixed
     */

    public function setNestedObjectValue($object, $propertyName, $value) {

        $attributeArray = Arr::get($object,'attributes',null);
        if ($attributeArray) {
            if (is_array($attributeArray)) {

                return Arr::set($object,'attributes', Arr::set($attributeArray,$propertyName,$value));

            }
        } else {
            return Arr::set($object,$propertyName,$value);
        }
    }



  /**
     * Reads an attribute from the data array asociated with the quote or application.
     *
     * You should only use this method to read from a nested array inside the data property
     *   - We provide this as a convenive method to wrap the 2 different "view" handlers we use
     *   for repeating objects like children or parents etc.
     *
     * One implmentation stores the data in a simple array structure:
     *
     * "children": [{
	 *	"idnum": "190101",
	 *	"gender": "M",
     *	"row_id": "9569eapcg",
	 *	"surname": "Cope",
	 *	"firstnames": "Chad",
     *  }],
     *
    * "beneficiaries": [{
	* "layout": "beneficiaries",
	*	"key": "dca8c4168b07942c",
	*	"attributes": {
	*		"title": "1",
	*		"firstnames": "Chad",
	*		"surname": "Copeland",
	*		"idnum": "8909115012084",
	*		"gender": "M",
	*		"share": "20",
	*		"relationship": "BR"
	*	}
    *
     *
     *
     * nestedObjectValue
     *
     * @param mixed $propertyName
     * @param string $defaultValue
     * @return mixed
     */
    public function nestedObjectValue($object, $propertyName, $defaultValue = '') {

        $attributeArray = Arr::get($object,'attributes',null);
        if ($attributeArray) {
            if (is_array($attributeArray)) {
                $value = Arr::get($attributeArray,$propertyName,$defaultValue);
            }
        } else {
            $value = Arr::get($object,$propertyName,$defaultValue);
        }
        return $value;
    }

    /**
     * Count the number of non empty properties in a list of properties
     *
     * Usefull to check if any values are filled in if they should not
     */
    protected function countEmptyValues($propertyNames) {
        $empty = 0;
        foreach ($propertyNames as $property) {
            $theValue = $this->objectValue($property);
            if ($theValue == '') $empty += 1;
            Log::debug("Read [$property] and value is [$theValue] empty [$empty]");
        }
        return $empty;
    }


    /**
     * Count the number of non empty properties in a list of properties
     *
     * Usefull to check if any values are filled in if they should not
     */
    protected function countNonEmptyValues($propertyNames) {
        $full = 0;
        foreach ($propertyNames as $property) {
            $theValue = $this->objectValue($property,'');
            if ($theValue != '') {
                Log::debug("We found a non empty value [$theValue]. Lets increase full count");
                $full += 1;
            }
            Log::debug("Read [$property] and value is [$theValue]. Full[$full]");
        }
        return $full;
    }


    /**
     * To deal with the numerous non-co-operating product houses, we allow for a very
     * generous key strategy
     *
     * We will inernally always use the application id (numeric) as the primary key
     *
     * We will also generate a uuid for use in APIs etc
     *
     * Then we allow a "shorter" key for external partners not able to accomodate a long uuid due to data size constraints
     *
     * Lastly we allow for 2 "product specific" keys that can be generated or served by the product logic
     */
    public function generateProductHouseKeys($app) {
        Log::debug('Default generateProductHouseKeys - we dont need anyting else');
    }


    //This is simple method that willl be called on quote init to check lead contains required fields
    protected function validateRequiredLeadFields($lead) {

        foreach ($this->required_lead_fields as $key) {
            $requiredValue =  Arr::get($lead,$key,'');
            if ($requiredValue === '') $this->stopInit("Required field [$key] not found in lead. Unable to continue. Please follow up with your lead provider.");
        }
    }

    //Classes meant to use this method to ensure lead has all data required downstream
    //so by quote AND app
    //Throw a stopInit if required info missing
    public function doValidateLead($lead) {

    }


    //Classes are meant to override this to set initial values and pull in
    //data from the lead
    public function initQuotation(Quotation $quote) {

        Log::debug("**************  NEW QUOTE INIT *****************[$quote->id]");
        Log::debug('We want to allow init values to be added to data');
        Log::debug('Data this far' . json_encode($quote->data));
        $quote->writeDataProperty('lastInit',now());
        Log::debug("**************  RESETTING WIZZARD *****************[$quote->id]");
        TsfFormWizard::resetAll('quotation');
        $this->setWorkingObject($quote->data);



        //Always hook into check for vlaid leads
        $this->validateRequiredLeadFields($quote->lead_data);

        //Single point to pull in user and team info at point of sale
        //$this->addEnvironmentInfo($quote);

        //todo: refactor so we consistently only pass in Quotataion object and not lead
        $this->doQuoteInit($quote->lead_data);
        $this->doQuoteInitFromQuote($quote);

        //Write values back into quote object
        $quote->data = $this->getWorkingObject();

    }

    public function doQuoteInit($lead) {
        Log::debug('Base class quote init. Hopefully someone else is doing some init stuff...');
    }

    public function doQuoteInitFromQuote($quote) {
        Log::debug('Base class quote init passed from Quote. Hopefully someone else is doing some init stuff...');
    }


    public function doQuoteCalculation() {
        Log::debug('Base class calculate. Hopefully someone else is doing some calcs...');
    }

    public function calculateQuoteValues(Quotation $quote) {

        Log::debug('We want to calculate quote values. Someone passed us a quote.');
        Log::debug('Data this far' . json_encode($quote->data));
        $quote->writeDataProperty('lastCalculated',now());
        $this->setWorkingObject($quote->data);

        //This is a place to stick commonly done calcs so we can DRY..
        $this->executePredefinedCalculations();

        //OK Here we expect the decendant class to actually do the calculation
        $this->doQuoteCalculation();


        //Write values back into quote object
        $quote->data = $this->getWorkingObject();

        //Make sure we have a value
        $this->assertQuotationOfferSet();

        $quote->offer_price = $this->quoteOfferValue;
        $quote->offer_description = $this->quoteOfferDescription;
        $quote->display_name = $this->getQuotationDisplayName();

        $quote->status = Quotation::STATE_VALID;

    }


    protected function doQuoteOpen($quote) {
        Log::debug('Base class nothing implemented in open quote');
    }

    public function openQuote(Quotation $quote) {

        Log::debug('Base class - will allow for stuff to happen on quote load');
        $quote->writeDataProperty('lastLoaded',now());
        $this->setWorkingObject($quote->data);

        //OK Here we expect the decendant class to actually do something if required
        $this->doQuoteOpen($quote);

        //Write values back into quote object
        $quote->data = $this->getWorkingObject();

    }


    public function doApplicationInit(Quotation $quote) {
        Log::debug('Base class app init. Hopefully someone else is doing some init stuff...');
    }

    public function doApplicationCalculation(Application $application) {
        Log::debug('Base class calculate. Hopefully someone else is doing some calcs...');
    }

    /**
     * Will be called "during" application set up - note that the application object does not yet
     * exist. If you need the app id - use the afterCreated event
     * @param Application $newApp
     * @param Quotation $quote
     * @return void
     * @throws Exception
     */
    public function initApplication(Application $newApp, Quotation $quote) {
        Log::debug('We want to allow init values to be added to application data');
        $quote->writeDataProperty('lastAppInit',now());
        Log::debug("**************  RESETTING WIZZARDS *****************");
        TsfFormWizard::resetAll('application');

        $this->setWorkingObject($newApp->data);

        //Lets pull lead key from lead
        $newApp->lead_key = Arr::get($quote->lead_data,$this->lead_key_field,'');

        //Moved into common area to always copy all quote data into app
        $quoteData = $quote->data;
        $this->setObjectValues($quoteData);

        //Here we do the check for client
        //This should really be from the quote - this needs serious fixing
        //todo: Make this less FUBAR
        $this->createClientFromApplication($newApp);


        $this->doApplicationInit($quote);


        //Write values back into quote object
        $newApp->data = $this->getWorkingObject();

    }


    public function afterAplicationCreated($application) {
        Log::Debug('^^^^^ LAST STEP: Product specific create hook');
        //We need to give any product specific create logic to run
        $this->generateProductHouseKeys($application);

    }

    protected function predefined_genderFromId() {

        foreach ($this->gender_from_id_fields as $fieldname) {
            $idnum = $this->objectValue($fieldname,null);
            if ($idnum) {
                $info = OTRHelper::extractRSAIdNumberInfo($idnum);
                $validId = Arr::get($info,'valid',false);
                if ($validId) {
                    $this->setObjectValue($fieldname . '_idinfo_gender',Arr::get($info,'gender',''));
                    $this->setObjectValue($fieldname . '_idinfo_citizenship',Arr::get($info,'citizenship',''));
                }
            }
        }

    }

    protected function predefined_ageCalculations() {

        $basedOn = now();
        if ($this->agecalcs_basedate_propname !== null) {
            $dateStr = $this->objectValue($this->agecalcs_basedate_propname);
            $basedOn = new Carbon($dateStr);
        }

        //See if we need to expand on any dates
        foreach ($this->expand_date_fields as $fieldname) {
            $dtestr = $this->objectValue($fieldname,null);
            if ($dtestr) {
                $dte = new Carbon($dtestr);
                $this->setObjectValues(OTRHelper::generateExpandedDateInfo($dte,$fieldname . '_format','_',$basedOn));
            }
        }

        //See if we need any ages
        foreach ($this->dob_from_id_fields as $idprop=>$error) {
            $rsaid = $this->objectValue($idprop);
            if ($rsaid <> '') {
                $dob = OTRHelper::dobFromRSAIdNumber($rsaid);
                if (!$dob)
                    $this->stopCalculation('Invalid ID number / DOB for ' . $error);
                $this->setObjectValues(OTRHelper::generateExpandedDateInfo($dob,$idprop . '_calcdob','_',$basedOn));
            }
        }

        foreach ($this->dob_from_id_array_fields as $idpropinfo=>$errorinfo) {


            $idInfoAsArray = explode('.',$idpropinfo);
            $errorInfoAsArray = explode('.',$errorinfo);
            //If we do not have 2 properties we can continue
            if (count($idInfoAsArray) != 2) $this->stopCalculation("Invalid property [$idpropinfo] for array based age calculations.");
            if (count($errorInfoAsArray) != 2) $this->stopCalculation("Invalid property [$errorinfo] for array based age calculations.");

            $entityArrayName = Arr::get($idInfoAsArray,0,'');
            $idPropName = Arr::get($idInfoAsArray,1,'');
            $singularEntityName = Arr::get($errorInfoAsArray,0,'');
            $errorPropertyName = Arr::get($errorInfoAsArray,1,'');
            $entities = $this->objectValue($entityArrayName,[]);
            if (!$entities) {

                $entities = [];
            }
            //Log::debug("We found [$entityArrayName] entities to work through:" . count($entities));
            //Now lets loop through all entities in array
            $entityCount = 1; //Lets count from 1 for humans...
            foreach ($entities as &$entity) {
                $rsaid = $this->nestedObjectValue($entity,$idPropName,'');
                if ($rsaid <> '') {
                    $dob = OTRHelper::dobFromRSAIdNumber($rsaid);
                    Log::debug($dob);
                    if (!$dob) {
                        Log::debug('We could not calculate dob');
                        $entityName = $this->nestedObjectValue($entity,$errorPropertyName);
                        $errorMessage = $singularEntityName . " [$entityCount] [$entityName] has invalid ID or DOB";
                        $this->stopCalculation($errorMessage);
                    }
                    $extraDateInfo = OTRHelper::generateExpandedDateInfo($dob,$idPropName . '_calcdob','_',$basedOn);
                    foreach ($extraDateInfo as $key=>$value) {
                        $entity = $this->setNestedObjectValue($entity,$key,$value);
                    }//foreach extraDateInfo
                $entityCount += 1;
                }//if we found an rsa id
            }//for each entity
            //Now we have to set entity array back to object
            Log::debug($entities);
            $this->setObjectValue($entityArrayName,$entities);
        }
    }

    public function predefined_dropdownExpand() {
        foreach ($this->dropdown_label_from_code as $dropdownCodeField => $dropdownKey) {
            $code = $this->objectValue($dropdownCodeField,false);
            Log::debug($code);
            Log::debug("^^PREDEF_CALC:[$dropdownCodeField]=[$dropdownKey] with code [$code]");
            if ($code) {
                $dropdown = $this->findProductDropDownOption($dropdownKey,$code);
                if ($dropdown) {
                    $newKeyName = $dropdownCodeField . '_display_label';
                    Log::debug("Found it will set [$newKeyName] to [$dropdown->display_label]");
                    $this->setObjectValue($newKeyName,Str::title($dropdown->display_label));
                } else {
                    Log::debug("Woops we could not find dropdown option with key[$dropdownKey] and code[$code]");
                }
            } else {
                Log::debug("^^PREDEF_CALC:No code in input");
            }
        }
    }

    public function executePredefinedCalculations() {
        Log::debug('We will execute some predefind calculations');
        $this->predefined_ageCalculations();
        $this->predefined_dropdownExpand();
        $this->predefined_genderFromId();

    }

    /**
     * We added this to use the validUntilDate property to prevent apps sitting in QA too long
     * @return void
     */
    public function getValidUntilDate() {
        Log::debug('Default valid until called. Lets just set to today plus 7 days');
        return now()->addDays(7);
    }

    public function calculateApplicationValues(Application $application) {

        Log::debug('We want to calculate application values. Someone passed us an app.');
        $application->writeDataProperty('lastAppCalculated',now());
        $this->setWorkingObject($application->data);

        //This is a place to stick commonly done calcs so we can DRY..
        $this->executePredefinedCalculations();

        //OK Here we expect the decendant class to actually do the calculation
        $this->doApplicationCalculation($application);


        $application->data = $this->getWorkingObject();

        //New: 8 May 2020
        //Fix 19 May - lets move this from calculate! Dont save during calculate
        //createClientFromApplication

        //Now have a single place to set the valid until date
        $application->valid_until = $this->getValidUntilDate();

        //Make sure we have a value
        $this->assertApplicationOfferSet();

        $application->offer_price = $this->applicationOfferValue;
        $application->offer_description = $this->applicationOfferDescription;
        $application->display_name = $this->getApplicationDisplayName();

        $application->status = Application::STATE_VALID;

    }


    public function transformApplication($transformMethod, Application $application) {

        Log::debug('We want to transform application using method ' . $transformMethod);
        $application->writeDataProperty('lastAppTransform',now());
        $application->writeDataProperty('lastAppTransform_method',$transformMethod);

        //Allow for easy working with object
        $this->setWorkingObject($application->data);

        //OK Here we expect the decendant class to actually do the transformation
        $transformMethodName = "doApplicationTransform" . $transformMethod;
        if (method_exists($this,$transformMethodName)) {
            Log::debug('Found method lets just call descendant transform');
            //Should get back TsfTransformResult
            $transformation = $this->$transformMethodName($application);
            Log::debug('Transformed. Lets evaluate result');
            if ($transformation->success === true) {
                Log::debug('Successfull transformation. Lets add');
                $application->addTransformation($transformMethod,$transformation->format,$transformation->raw);
                $application->data = $this->getWorkingObject();
                if ($transformation->success_status) {
                    Log::debug("We must update the application status to [".$transformation->success_status."]");
                    $application->changeStatus($transformation->success_status);
                } else {
                    Log::debug('No need to update application status');
                }
            } else {
                JobLog::error('Transformation failed for application id ['.$application->id.'] and method ['.$transformMethod.']');
                Log::error('Transformation failed!. Lets see if we need to update status');
                if ($transformation->failed_status) {
                    Log::debug("We must update the application status to [".$transformation->failed_status."]");
                    $application->changeStatus($transformation->failed_status);
                } else {
                    Log::debug('No need to update application status');
                }

            }
            Log::debug('Finally lets save. Will skip calculations');
            $application->forceSkipCalculation();
            $application->save();
        } else {
            Log::debug('No transform method implemented.');
        }

        //Write values back into quote object

    }

    public function setDataFromApp($appid) {
        $app = Application::findOrFail($appid);
        $this->setWorkingObject($app->data);
    }


    public function applicationConfirmFields($app, $request) {
        return [
           // BelongsTo::make('User')->onlyOnDetails(),
        ];
    }

    public function applicationAwaitOptinFields($app,$request) {
        return [];
    }

    public function applicationAwaitDocumentFields($app,$request) {
        return [];
    }


    public function customizedQuoteAcceptWorkflow($quote, $newapplication) {
        //By default we do nothing
        return null;
    }

    /**
     * Specifics should override this and based on the process create an array of params to handoff to some other form
     * @param mixed $process
     * @return array with key=value pairs
     */
    public function buildHandoffParams($process, Application $application) {
        return [];
    }


    /**
     *  We add this to product as well since we dont want to cater for every conceivable mechansim
     *
     * @param mixed $request
     * @param mixed $data
     * @param mixed $url
     * @return mixed
     */

    public function doGetHandoff($request, $data, $url) {
        Log::debug('Default get handoff called. Will just slap data at end of url');
        $fullUrl = $url . '?' . Arr::query($data);
        JobLog::info('Redirecting to:' . $fullUrl);
        return redirect($fullUrl);
    }

    public function doPostHandoff($request, $data, $url) {
        //tbc
        return 'nyi';
    }

    public function doHandoffResponse($process, Application $app) {
        Log::debug('Default  handoff called. We will return false, since some product should handle this');
        return false;
    }

    /**
     * We will now also allow products to add actions specifically tailored to them
     *
     * @param mixed $application
     * @return void
     */
    public function addCustomApplicationActions($application) {
        //Base class just returns an empty array
        return [];
    }

    protected function readMappedProperty($data, $map, $propertyName, $defaultValue) {

        $key = Arr::get($map,$propertyName,$propertyName);
        $value = Arr::get($data,$key,$defaultValue);
        if ($value === null) return $defaultValue;
        return $value;
    }


    public function createClientFromApplication(Application $application) {
        //We will use the data defined in the
        if ($application->client) return true;
        Log::debug('We do not have a client! Lets create one from the application data if we can');
        $newClient = new Client();
        $newClient->client_type = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'client_type',AppConstants::CLIENT_TYPE_PERSON);
        $newClient->id_type = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'id_type',AppConstants::ID_TYPE_RSA);
        $newClient->title = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'title',null);
        $newClient->first_name = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'first_name',null);
        $newClient->surname = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'surname',null);
        $newClient->id_number = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'id_number',null);
        $newClient->passport_number = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'passport_number',null);
        $newClient->gender = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'gender',null);
        $newClient->date_of_birth = $this->readMappedProperty($application->data,$this->client_field_map,
                                  'date_of_birth',null);



        $existingClient = $newClient->exists();
        if ($existingClient) {
            Log::debug('We already have this client, lets use');
            $client = $existingClient;
        } else {
            $newClient->save();
            Log::debug('Saved new client to db id is ' . $newClient->id);
            $client = $newClient;
        }

        //We should maybe throw something if we do not have enough info to create a client
        //todo: we must not always create a new one

        $application->client_id = $client->id;
        if ($application->quotation) {
            Log::debug('No client on quote either - lets add');
            if (!$application->quotation->client) {
                $application->quotation->client_id = $client->id;
                Log::debug('Silent saving quote');
                $application->quotation->silentSave();
            }
        }
        return $client;
    }



    public function createClientFromQuotation(Quotation $quotation) {
        //We will use the data defined in the
        if ($quotation->client) return true;
        Log::debug('We do not have a client! Lets create one from the quotation data if we can');
        //Try to find my idnumber
        $newClient = null;
        $peek = $this->readMappedProperty($quotation->data,$this->client_field_map,'id_number',null);
        if ($peek)
            $newClient = Client::where('id_number',$peek)->first();
        if (!$newClient)
            $peek = $this->readMappedProperty($quotation->data,$this->client_field_map,'passport_number',null);
        if ($peek) $newClient = Client::where('passport_number',$peek)->first();
        if (!$newClient) {
            Log::debug('We could not find an existing client lets new up');
            $newClient = new Client();
            $newClient->client_type = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'client_type',AppConstants::CLIENT_TYPE_PERSON);
            $newClient->id_type = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'id_type',AppConstants::ID_TYPE_RSA);
            $newClient->title = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'title',null);
            $newClient->first_name = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'first_name',null);
            $newClient->surname = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'surname',null);
            $newClient->id_number = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'id_number',null);
            $newClient->passport_number = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'passport_number',null);
            $newClient->gender = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'gender',null);
            $newClient->date_of_birth = $this->readMappedProperty($quotation->data,$this->client_field_map,
                                    'date_of_birth',null);



            $newClient->save();
        }

        Log::debug('Client to db id is ' . $newClient->id);
        $quotation->client_id = $newClient->id;

    }


    public function canSeeAction(Application $application, $actionClass) {
        //By default we just say no
        return false;
    }

    public function canRunAction(Application $application, $actionClass) {
        //By default we just say no
        return false;
    }


    public function canSeeQuotationAction(Quotation $quote, $actionClass) {
        //By default we just say yes
        return true;
    }

    public function canRunQuotationAction(Quotation $quote, $actionClass) {
        //By default we just say yes
        return true;
    }



    public function filterDataStartsWith($startWith) {
        if (!is_array($this->object)) return [];
        $filtered = Arr::where($this->object, function ($value, $key) use ($startWith) {
            return Str::startsWith($key, $startWith);
        });
        return $filtered;
    }

    /**
     * Subclasses must implement if wnating to prvent new business for some custome reason
     *
     * @param mixed $lead
     * @return void
     */
    protected function customPreventNewBusiness($lead, $user) {

        //Base class we dont do anything
        return false;

    }

    /**
     * This method will be called BEFORE new business event (new from lead or new quote)
     *
     * The idea is to do ANY checks you want to be in place for this product to be created.
     *
     * Good examples are:
     *      Products that require sales agent details - make sure the user has these defined
     *      Also possible to add some logic to prevent usage of lead if passed in (maybe not authroised etc)
     *      Also sensible place to add "Duplicate" check going forward
     *
     * @param mixed $lead
     * @return void
     */
    public function preventNewBusiness($lead = null) {

        //Default implementation will check for sales id - if you override make sure to call parent::preventNewBusiness
        if ($this->requires_salesidentity) {
             if (!AppConstants::currentUser()->hasIdentityDetails()) {
                 $this->preventNewBusinessReason = 'The product requires Sales Agent identity information and your account is not configured correctly. Update your details or contact your manager.';
                 return true;
             }
        }

        $user = AppConstants::currentUser();
        Log::debug('Lets have a look at any required props for user : ' . $user->name);
        //Now lets have a look at required props
        foreach ($this->newbusiness_required_user_properties as $propertyLabel => $property) {
            Log::debug("We need [$propertyLabel] [$property]. Lets check");
            $peek = $user->configProperty($property,'');
            Log::debug("Value set to [$peek]");
            if ($peek == '') {
                Log::debug("Not found so lets return and stop");
                $this->preventNewBusinessReason = "This product requires [$propertyLabel]. Update your details or contact your manager / team leader to assist.";
                //We will bugger off as soon as we hit one missing prop
                return true;
            }
        }

        Log::debug('All still good lets give product deff final chance to prevent...');
        //If we are still here we can now allow for a custom check on the product config
        return $this->customPreventNewBusiness($lead, AppConstants::currentUser());

    }

    /**
     * This method will be called in the event that preventNewBusiness method returned true, this message should contain message
     * simplest way is to set the protected property $preventNewBusinessReason
     * @return string
     */
    public function newBusinessPreventionReason() {
        return $this->preventNewBusinessReason;
    }


    /**
     * Initial simplistic workflow "engine" - realy just a method called based on this logic:
     *
     *   From applicaton moving from CAPTURED to REJECTED:
     *
     *   Try the following methods in order and first one is called:
     *
     *   //Should be in ProductLogic class
     *
     *   workflow_from_captured_to_rejected
     *   workflow_to_rejected
     *
     *   // Should be in base class
     *
     *   default_workflow_from_captured_to_rejected
     *   default_workflow_to_rejected
     *
     *
     *
     * @param Application $application
     * @param mixed $fromStatus
     * @param mixed $newStatus
     * @return void
     */
    public function doStatusChangedWorkflows(Application $application, $fromStatus, $newStatus) {
            $me = class_basename($this);
            Log::debug('%%%%% BASE CLASS doStatusChangedWorkflows for:' . $me);
            //Lets first see if an exact method exists
            $flowName = OTRHelper::makeStatusMethodName(AppConstants::WORKFLOW, $fromStatus, $newStatus);
            if (method_exists($this,$flowName)) {
                return $this->$flowName($application, $fromStatus, $newStatus);
            }

            //If still here we have not yet found a method - lets look at the less specfic one
            $flowName = OTRHelper::makeStatusMethodName(AppConstants::WORKFLOW, $fromStatus, $newStatus, false);
            if (method_exists($this,$flowName)) {
                return $this->$flowName($application, $fromStatus, $newStatus);
            }

            //If still here then lets fall back to default implementation - specific
            $flowName = OTRHelper::makeStatusMethodName(AppConstants::DEFAULT_WORKFLOW, $fromStatus, $newStatus);
            if (method_exists($this,$flowName)) {
                return $this->$flowName($application, $fromStatus, $newStatus);
            }

            //Last try lets see if there is a non specific default workflow
            $flowName = OTRHelper::makeStatusMethodName(AppConstants::DEFAULT_WORKFLOW, $fromStatus, $newStatus, false);
            if (method_exists($this,$flowName)) {
                return $this->$flowName($application, $fromStatus, $newStatus);
            }

            //Ok dead end no one cares about this - lets log that for what its worth
            JobLog::info("No workflow method defined for [$me] [$fromStatus] [$newStatus]");


    }


    public function provideConfigurationFields(Product $product, $request) {
        Log::debug('Base class provide config - at this stage we do not have any "global" ones so we rely on product to provide');
        return [];
    }

    /**
     *  Allow for easy access to any product configuration settings specified on the product
     *
     * @param mixed $key
     * @param mixed $defaultValue
     * @return void
     */
    public function readProductConfig($key, $defaultValue) {
        return Arr::get($this->product->config,$key,$defaultValue);
    }

    /**
     * This should allow product house logic to make any updates to the quote or app data
     *
     * YOU MUST RETURN THE DATA YOU RECEIVE
     *
     * You could handle this as a mini calculate
     *
     * @param mixed $wizardname
     * @param mixed $currentStep
     * @param mixed $nextStep
     * @param mixed $data
     * @return void
     */
    public function allowWizardStepDataModify($wizardname, $currentStepIndex, $currentStep, $nextStep, $data) {
        JobLog::info("Wizard [$wizardname] Step [$currentStepIndex] Allow Change from page [$currentStep] to [$nextStep]",$this);
        if ($data) {
            $this->setWorkingObject($data);
            $this->executePredefinedCalculations();
            $this->objectValue('wizzy_' . $currentStep, 'How can you laaik to have beeeen?');
            return $this->getWorkingObject();
        }
        return $data;
    }

    /**
     *
     * The default response handler for submit calls - override in your product to implement custom processing of a product house
     * response
     *
     * @param Application $application
     * @param mixed $response
     * @return true
     */

    public function handleSubmitResponse(Application $application, $response) {

        Log::debug('Default API response handler in TsfProductDefinition. Will simply mark as accepted');
        $application->changeStatus(AppConstants::STATE_API_OK,'Received back OK from API');
        $application->changeStatus(AppConstants::STATE_ACCEPTED);
        return true;

    }


    public function createApplicationFromApi($payload) {

    }

    public function verifyApi() {
        return "Failed: No test implemented";
    }

    public function recalculate(Application $app) {
        Log::debug('Base TSF deffinition recalculate called');
        return true;
    }


}
