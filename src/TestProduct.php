<?php

namespace haythembenkhlifa\haythemproduct;

use App\Quotation;
use App\Application;
use NovaButton\Button;
use App\Rules\IdNumber;
use R64\NovaFields\Row;
use Illuminate\Support\Arr;
use Laravel\Nova\Fields\Text;
use App\Rules\CheckApplication;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\JsonDate;
use Illuminate\Support\Facades\Log;
use R64\NovaFields\Text as GridText;
use Laravel\Nova\Http\Requests\NovaRequest;
use haythembenkhlifa\haythemproduct\TsfProductDefinition;

class TestProduct extends TsfProductDefinition
{

    /**
     * Format for adding dob from id for repeating fields:
     *
     * Add the array property name followed by "dot" and the idnumber property
     * Then assign error info by using a singular label and a field to use in the error message to indicate to the
     * user which entity has an error
     *
     *   "children.idnum" => "Child.firstname"
     *
     * So for example, if the second row has an invalid ID number, the
     * error message will be generated using the firstname property.
     *
     * Error would be something like
     *   Child [2] [James] has an invalid ID number
     *
     */
    protected $dob_from_id_array_fields = [
        "children.idnum" => "Child.firstname"

    ];


    public function quoteFields($quote) {


        return [
            Text::make('Client age (from lead)','data->client_age'),
            Text::make('Dogs name','data->dogs_name'),
            JsonDate::make('Dogs date of birth','data->dog_dob')->format('YYYY-MM-DD')
            ->rules('required'),
        ];

    }

    public function applicationConfirmFields($app, $request) {
        return [
            Text::make('Total premium','data->premium')->readonly(true)
        ];
    }



    public function applicationFields() {
        Log::debug('We are being asked for fields');
        return [
            Text::make('Dog name (from quote)','data->dogs_name'),
            Text::make('Contact number','data->contact_number'),
            Text::make('Bank account number','data->bank_account'),

            //This illustrates teh use case where add more fields to the array field
        ];

    }
    /**
     * This method will be called when the quote is create for the first time
     * This gives an opportunity to pull data in from the lead (which is passed in)
     * or init default values etc.
     *
     * If minimum info is not available (from lead for example) stop the process by callin
     * stopInit with an error reason
     *
     */
    public function doQuoteInit($lead) {
        Log::debug('Test product init. We received:' . json_encode($lead));
        $theAge = Arr::get($lead,'age',0);
        //if ($theAge === 0) $this->stopInit('Could not init quotation. Required lead data missing: [age]');
        Log::debug('We received age:' . $theAge);
        //Use the setObjectValue helper method to set any values in the data object
        $this->setObjectValue('client_age',$theAge);

    }


    public function doQuoteCalculation() {
        Log::debug('Test class quote calcs');

        //Showing how to work with the grid objects - read back like this and it will return an array

        $children = $this->objectValue('children',[]); //Note the default value is empty array so you can do counts etc
        $childrenCount = count($children);

        //Example busines rule - to stop calculation simply call stopCalculation method with error reason:
        //if ($childrenCount < 1) $this->stopCalculation('You must add at least 1 children');

        //If you want to modify the contents of a nested array (like grid value field children)
        //you have to keep the array updated by adding the & before the object - &$child in the example
        // foreach ($children as &$child) {
        //     $child['dog_name'] = $this->objectValue('dogs_name');
        // }
        //And remember - if you did modify the array you MUST write it back like below with a setObjectValue
        $this->setObjectValue('children',$children);

        //And here is an example of just adding any additional values to the data:
        $this->setObjectValue('some.result','12');
        $this->setObjectValue('rate.perchild','125');

        //Finally - if quote is valie, you must specify an offerValue
        $this->quoteOfferValue = 125.00;
        $this->quoteOfferDescription = "Cover for children";
    }


    public function doApplicationInit(Quotation $quote) {
        Log::debug('Test app application init');

        //By default all data from the quote will be the app data
        //Lets show how we keep data from the quote to make sure its not changed
        //For example - lets store how many children were added at quote time

        $children = $this->objectValue('children',[]); //Note the default value is empty array so you can do counts etc
        $childrenCount = count($children);

        $this->setObjectValue('quote.childcount',$childrenCount);

        //If you find any problems - simply call stopInit with a reason
        //$this->stopInit('No don't do it');
    }

    public function doApplicationCalculation(Application $application) {
        Log::debug('Test class application calcs');

        //Showing how to work with the grid objects - read back like this and it will return an array

        $children = $this->objectValue('children',[]); //Note the default value is empty array so you can do counts etc
        $childrenCount = count($children);
        $childrenOnQuote = $this->objectValue('quote.childcount',0);
        Log::debug("Children on quote [$childrenOnQuote] and on app [$childrenCount]");

        //Example bussines rule here - we want to make sure the guy did not add
        //additional children in the app.
       /// if ($childrenCount <> $childrenOnQuote) $this->stopCalculation("Children on quote [$childrenOnQuote] and on app [$childrenCount]");


        $application->offer_price = 199;
        Log::debug('Offer price is ' . $application->offer_price);

        $this->setObjectValue('premium',$application->offer_price);

        //If this is all good we can just leave the offer as we got it.
        $this->applicationOfferValue = $application->offer_price;
        $this->applicationOfferDescription = 'Updated application';

    }


}
