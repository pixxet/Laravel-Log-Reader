<?php

namespace App\Modules\RSSRiskcheckMonitoring\Helpers\Reader;

use App\Helpers\HelpersCore;
use Carbon\Carbon;
use DB;
use LogReader;
use Pi;
use Storage;

class Reader extends HelpersCore
{
    protected $cogs;
    protected $payment_types;
    protected $riskcheck_payments_table;

    public function __construct()
    {

        $this->cogs = json_decode(Pi::Helper('RSSRiskcheckMonitoring::Settings')->getRSSCogs());

        foreach ($this->cogs->DEFAULT as $key => $value) {
            Pi::Model('RSSRiskcheckMonitoring::Payment')->firstOrCreate([
                'name' => $key,
            ]);
        }

        $this->payment_types = Pi::Model('RSSRiskcheckMonitoring::Payment')->pluck('id', 'name');

        $this->riskcheck_payments_table = with(Pi::Model('RSSRiskcheckMonitoring::Riskcheck\RiskcheckPayment'))->getTable();

        parent::__construct();
    }
    /**
     * fill/re-fill riskchecks from the rss riskcheck log files
     *
     * @return null
     */
    public function fillLogFiles()
    {

        //get files from the storage disk, value of the riskchecks path was saved over the module provider
        $files = Storage::disk('riskchecks')->allFiles();

        foreach ($files as $file) {

            // select file model or create new WITHOUT saving to db
            $file = Pi::Model('RSSRiskcheckMonitoring::Riskcheck\RiskcheckFile')->firstOrNew(['filename' => $file]);

            $riskchecks = $this->parseFile($file->filename);

            // if file exists in the db compare its riskchecks, true => delete and refill, false => save and fill
            if ($file->exists && $riskchecks->count() != $file->riskchecks()->count()) {
                $file->riskchecks()->delete();
                $this->injectRiskcheks($riskchecks, $file);
            } else if (!$file->exists) {
                $file->save();
                $this->injectRiskcheks($riskchecks, $file);
            }

            $timeouts = $this->parseTimeOutFile($file->filename);

            if ($timeouts->count()) {
                $this->injectTimeouts($timeouts, $file);
            }
        }
    }

    /**
     * parsing riskcheck log files
     * @param  String  $filepath
     * @return LogReader object with parsed specific log file
     */
    protected function parseFile($filepath)
    {
        // get module logParser
        $parser = new \App\Modules\RSSRiskcheckMonitoring\Classes\LogParser;
        //set the logParser
        LogReader::setLogParser($parser);
        //set the log path, value was saved over the module provider
        LogReader::setLogPath(Storage::disk('riskchecks')->path(null));

        // return the parsed Log file
        return LogReader::filename($filepath)->get();
    }

    /**
     * injecting riskchecks
     * @param  Illuminate\Support\Collection  $riskchecks
     * @param  App\Modules\RSSRiskcheckMonitoring\Models\Riskcheck\RiskcheckFile  $file
     * @return null
     */
    protected function injectRiskcheks($riskchecks, $file)
    {

        foreach ($riskchecks as $riskcheck) {

            // decode json body context
            $context = json_decode($riskcheck->getAttribute('context'));

            // get payments Configurations based on result code
            $payments = (isset($this->cogs->{$context->decision->resultCode}) ? $this->cogs->{$context->decision->resultCode} : $this->cogs->DEFAULT);

            // create Model
            $riskcheck = [
                'file_id'              => $file->id,
                'result_code'          => $context->decision->resultCode,
                'new_customer_request' => $context->decision->isNewCustomer,
                'requestTime'          => Carbon::createFromFormat('Y-m-d H:i:s', $riskcheck->date, 'GMT'),
                'payments'             => $payments,
            ];
            $riskcheck = Pi::Model('RSSRiskcheckMonitoring::Riskcheck')->create($riskcheck);

            if ($riskcheck) {
                $payments_insert = [];
                foreach ($payments as $key => $value) {
                    if ($value) {
                        $payments_insert[] = [
                            'riskcheck_id'               => $riskcheck->id,
                            'riskcheck_payment_types_id' => $this->payment_types[$key],
                        ];
                    }

                }
                if (count($payments_insert)) {
                    // insert data
                    DB::table($this->riskcheck_payments_table)->insert($payments_insert);
                }
            }
        }
    }

    /**
     * parsing timeouts in riskcheck log files
     * @param  String  $filepath
     * @return LogReader object with parsed specific log file
     */
    protected function parseTimeOutFile($filepath)
    {
        // get module logParser
        $parser = new \App\Modules\RSSRiskcheckMonitoring\Classes\TimoutLogParser;
        //set the logParser
        LogReader::setLogParser($parser);
        //set the log path, value was saved over the module provider
        LogReader::setLogPath(Storage::disk('riskchecks')->path(null));

        // return the parsed Log file
        return LogReader::filename($filepath)->get();
    }

    /**
     * injecting timeouts
     * @param  Illuminate\Support\Collection  $timeouts
     * @param  App\Modules\RSSRiskcheckMonitoring\Models\Riskcheck\RiskcheckFile  $file
     * @return null
     */
    protected function injectTimeouts($timeouts, $file)
    {
        foreach ($timeouts as $timeout) {

            // decode json body context
            $context = json_decode($timeout->getAttribute('context'));

            // create Model
            $timeout = [
                'file_id'     => $file->id,
                'requestTime' => Carbon::createFromFormat('Y-m-d H:i:s', $timeout->date, 'GMT'),
            ];

            $timeout = Pi::Model('RSSRiskcheckMonitoring::Timeout')->create($timeout);
        }
    }
}
