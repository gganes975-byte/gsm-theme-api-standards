<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Helpers\{ApiHelper, PriceHelper, SystemHelper, InsertHelper, InstantApiSupportHelper};
use App\Models\{Customer, CustomerOrder, ServiceGroup, ServiceList, ServiceInput, OrderInput, Statement, CustomPrice, SystemSetting, MailData};
use Carbon\Carbon;

class PublicApiController extends Controller
{
    public function publicApi(Request $request)
    {
        $isApiValid = isValidIncommingApi($request);

        // Validate Authentication
        if(!$isApiValid['valid']){
            return $this->error($isApiValid['message']);
        }

        $customer = $isApiValid['customer'];
        
        return match ($request->action) {
            'accountinfo' => $this->accountInfo($customer),
            'imeiservicelist' => $this->imeiServiceList($customer),
            'getimeiorder' => $this->getImeiOrder($request, $customer),
            'getimeiorderbulk' => $this->getImeiOrderBulk($request, $customer),
            'placeimeiorder' => $this->placeImeiOrder($request, $customer),
            'placebulkorder' => $this->placeBulkOrder($request, $customer),
            default => $this->error('Invalid Action'),
        };
    }

    // RETURN AS ERROR
    private function error($message, $code = 200)
    {
        $data = [
            'ERROR' => [['MESSAGE' => $message]],
            'apiversion' => apiVersion(),
        ];
        
        return response()
            ->json($data, $code)
            ->header('X-Powered-By', 'GSM-THEME')
            ->header('gsmtheme-api-version', apiVersion())
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    // RETURN AS SUCCESS
    private function success(array $data, $code = 200)
    {
        $data['apiversion'] = apiVersion();

        return response()
            ->json($data, $code)
            ->header('X-Powered-By', 'GSM-THEME')
            ->header('gsmtheme-api-version', apiVersion())
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    // RETURN AS BULK
    private function bulkReturn(array $data, $code = 200)
    {
        $data['apiversion'] = apiVersion();

        return response()
            ->json($data, $code)
            ->header('X-Powered-By', 'GSM-THEME')
            ->header('gsmtheme-api-version', apiVersion())
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    // ACCOUNT INFO
    private function accountInfo($customer)
    {
        try {
            return $this->success([
                'SUCCESS' => [[
                    'MESSAGE' => 'Your Account Info',
                    'AccountInfo' => [
                        'credit' => round($customer->balance, 2) . ' ' . $customer->currency,
                        'creditraw' => round($customer->balance, 2),
                        'mail' => $customer->email,
                        'currency' => $customer->currency
                    ]
                ]]
            ]);

        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // SERVICE LIST
    private function imeiServiceList($customer)
    {
        try {

            if ($customer->next_account_sync > now()) {
                $nextTime = now()->diffInMinutes($customer->next_account_sync);
                return $this->error('You are calling this API too frequently! Please try after ' . $nextTime . ' minutes.', 200);
            }
            $customer->update([
                'next_account_sync' => now()->addMinutes(5),
            ]);

            $serviceGroups = ServiceGroup::where('status', 'Active')->orderBy('name')->get(['id', 'type', 'name', 'status']);
            $serviceList = [];

            foreach ($serviceGroups as $serviceGroup) {
                $availableService = ServiceList::where('status', 'Active')->where('service_group', $serviceGroup->id)->count();
                if($availableService){
                    $groupName = $serviceGroup->name;
                    $serviceList[$groupName] = [
                        'GROUPNAME' => $groupName,
                        'GROUPTYPE' => match (strtoupper($serviceGroup->type)) {
                            'SERVER' => 'SERVER',
                            'IMEI' => 'IMEI',
                            'REMOTE' => 'REMOTE',
                            default => 'REMOTE',
                        },
                        'SERVICES' => []
                    ];

                    $services = ServiceList::where('service_group', $serviceGroup->id)->where('status', 'Active')->orderBy('title', 'DESC')->get();

                    foreach ($services as $service) {

                        $serviceList[$groupName]['SERVICES'][$service->id] = [
                            'SERVICEID' => $service->id,
                            'SERVICETYPE' => match (strtoupper($serviceGroup->type)) {
                                'SERVER' => 'SERVER',
                                'IMEI' => 'IMEI',
                                'REMOTE' => 'REMOTE',
                                default => 'REMOTE',
                            },
                            'SERVER' => match (strtoupper($serviceGroup->type)) {
                                'SERVER' => 1,
                                'IMEI' => 0,
                                'REMOTE' => 2,
                                default => 2,
                            },
                            'QNT' => $service->min_qnt ? 1 : 0,
                            'MINQNT' => $service->min_qnt,
                            'MAXQNT' => $service->max_qnt,
                            'SERVICENAME' => $service->title,
                            'CREDIT' => calculateServicePrice($service->id, 1, $customer->id),
                            'TIME' => $service->delivery_time,
                            'INFO' => ''
                        ];

                        $inputFields = ServiceInput::where('service_id', $service->id)->get();
                        $customFields = $service->service_type === 'IMEI' ? $inputFields->skip(1) : $inputFields;

                        if ($service->service_type === 'IMEI' && $inputFields->first()) {
                            $serviceList[$groupName]['SERVICES'][$service->id]['CUSTOM'] = [
                                'allow' => '1',
                                'bulk' => '0',
                                'customname' => $inputFields->first()->name,
                                'custominfo' => '',
                                'customlen' => '1',
                                'maxlength' => '300',
                                'regex' => '',
                                'isalpha' => '1'
                            ];
                        }

                        if ($customFields->isNotEmpty()) {
                            $custom = [];
                            foreach ($customFields as $i => $input) {
                                $custom[$i] = [
                                    'type' => 'serviceimei',
                                    'fieldname' => $input->name,
                                    'fieldtype' => 'text',
                                    'description' => '',
                                    'fieldoptions' => '',
                                    'required' => 'on'
                                ];
                            }
                            $serviceList[$groupName]['SERVICES'][$service->id]['Requires.Custom'] = $custom;
                        }
                    }
                }
            }

            return $this->success([
                'SUCCESS' => [[
                    'MESSAGE' => 'Service List',
                    'LIST' => $serviceList,
                    'ACCOUNTINFO' => [
                        'credit' => round($customer->balance, 2) . ' ' . $customer->currency,
                        'creditraw' => round($customer->balance, 2),
                        'mail' => $customer->email,
                        'currency' => $customer->currency
                    ]
                ]]
            ]);

        } catch (\Exception $e) {

            return $this->error($e->getMessage());
        }
    }

    // ORDER HISTORY
    private function getImeiOrder(Request $request, $customer)
    {
        try {
            $params = simplexml_load_string($request->parameters);
            if (!$params || !isset($params->ID)) {
                return $this->error('Parameter required.');
            }

            $order = CustomerOrder::where('customer_id', $customer->id)->find((int)$params->ID);
            if (!$order) {
                return $this->error('Order ID not found!');
            }

            $statusMap = [
                'Success' => 4,
                'Rejected' => 3,
                'In Process' => 1,
                'Waiting Action' => 0
            ];

            $status = $statusMap[$order->service_status] ?? 0;

            return $this->success([
                'SUCCESS' => [[
                    'STATUS' => $status,
                    'CODE' => $order->service_comments
                ]]
            ]);
        } catch (\Exception $e) {
            
            return $this->error($e->getMessage());
        }
    }

    // ORDER HISTORY BULK
    private function getImeiOrderBulk($request, $customer)
    {
        try {

            if(!isset($request->parameters) && empty($request->parameters)){
                return $this->error('Parameters required for bulk orders.');
            }

            $encodedParams = (string)$request->parameters;

            if (!isBase64($encodedParams)) {
                return $this->error('Parameters must be encoded with base64.');
            }

            $parameters = json_decode(base64_decode($encodedParams), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->error('Parameters must be a valid JSON.');
            }

            if (!is_array($parameters)) {
                return $this->error('Parameters must be a valid JSON array.');
            }

            $bulkReturn = [];

            foreach ($parameters as $refId => $para){
                
                $order = CustomerOrder::where('customer_id', $customer->id)->where('id', $para['ID'])->first();
                
                if($order){

                    if($order->service_status == 'Success'){
                        $bulkReturn[$refId]['SUCCESS'][] = [
                            'STATUS' => 4,
                            'CODE' => $order->service_comments,
                        ];
                    }
                    elseif($order->service_status == 'Rejected'){
                        $bulkReturn[$refId]['SUCCESS'][] = [
                            'STATUS' => 3,
                            'CODE' => $order->service_comments,
                        ];
                    }
                    elseif($order->service_status == 'In Process'){
                        $bulkReturn[$refId]['SUCCESS'][] = [
                            'STATUS' => 1,
                            'CODE' => '',
                        ];
                    }
                    else{
                        $bulkReturn[$refId]['SUCCESS'][] = [
                            'STATUS' => 0,
                            'CODE' => '',
                        ];
                    }

                }
                else{

                    $bulkReturn[$refId]['SUCCESS'][] = [
                        'STATUS' => 0,
                        'CODE' => '',
                    ];

                }
            }

            return $this->bulkReturn($bulkReturn);

        } catch (\Exception $e) {

            return $this->error($e->getMessage());
        }
    }

    // PLACE ORDER
    private function placeImeiOrder($request, $customer)
    {
        try {
            
            $params = @simplexml_load_string($request->parameters);
            if (!$params || !isset($params->ID)) {
                return $this->error('Parameter or Service <ID> missing.');
            }


            $serviceId = (int)$params->ID;
            $IMEI = null;
            $fields = [];
            $QNT = (int)$params->QNT ?: 1;

            if(isset($params->IMEI) && !empty($params->IMEI)){
                $IMEI = (string)$params->IMEI;
            }
            
            if(!empty($params->CUSTOMFIELD)){
                $fields = (string)$params->CUSTOMFIELD;
            }

            $permission = orderCreatePermission($customer->id, $serviceId, $QNT, 'Api', $fields, $IMEI);

            if (!$permission['permitted']) {

                return $this->error($permission['message']);
            }
            else{

                if($permission['permittedOrder'] === 'createPaidOrder'){

                    $order = createPaidOrder($customer->id, $serviceId, $permission['price'], $QNT, $permission['fields']);

                    return $this->success([
                        'SUCCESS' => [[
                            'MESSAGE' => 'Order received',
                            'REFERENCEID' => $order->id
                        ]]
                    ]);

                }
                else{
                    return $this->error('Not enough balance');
                }
            }


        } catch (\Exception $e) {

            return $this->error($e->getMessage());
        }
    }

    // PLACE BULLK ORDER
    function placeBulkOrder($request, $customer){

        if(!isset($request->parameters) && empty($request->parameters)){
            return $this->error('Parameters required for bulk orders.');
        }

        $encodedParams = (string)$request->parameters;

        if (!isBase64($encodedParams)) {
            return $this->error('Parameters must be encoded with base64.');
        }

        $parameters = json_decode(base64_decode($encodedParams), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('Parameters must be a valid JSON.');
        }

        if (!is_array($parameters)) {
            return $this->error('Parameters must be a valid JSON array.');
        }

        $bulkReturn = [];

        foreach ($parameters as $refId => $orderRequest) {
            $serviceId     = $orderRequest['ID'] ?? null;
            $qnt    = $orderRequest['QNT'] ?? 1;
            $fields = $orderRequest['CUSTOMFIELD'] ?? [];

            if (!$serviceId) {
                $bulkReturn['SUCCESS'][$refId] = [
                    'status' => 'error',
                    'message' => 'Missing targeted service id.'
                ];
                continue;
            }

            $permission = orderCreatePermission($customer->id, $serviceId, $qnt, 'Api', $fields);

            if (!$permission['permitted']) {
                $bulkReturn['SUCCESS'][$refId] = [
                    'status' => 'error',
                    'message' => $permission['message']
                ];
                continue;
            }

            if ($permission['permittedOrder'] === 'createPaidOrder') {
                $order = createPaidOrder($customer->id, $serviceId, $permission['price'], $qnt, $permission['fields']);

                $bulkReturn['SUCCESS'][$refId] = [
                    'status' => 'success',
                    'message' => 'Order received',
                    'referenceid' => $order->id
                ];
            }
            else{
                $bulkReturn['SUCCESS'][$refId] = [
                    'status' => 'error',
                    'message' => 'Not enough balance'
                ];
            }
        }

        return $this->bulkReturn($bulkReturn);

    }

}
