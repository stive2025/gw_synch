<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class SynchronizationController extends Controller
{
    private const MINIMUM_DATE = '2025/09/01 00:00:00';
    private const ACTIVE_STATE = 'ACTIVA';
    private const API_TYPE = 'api';
    
    public function syncPays()
    {
        try {
            $creditos = $this->getCreditsToSync();
            $campaignId = $this->getActiveCampaignId();
            
            $processedCount = 0;
            $errorCount = 0;

            foreach ($creditos as $credito) {
                try {
                    $this->processCreditPayments($credito, $campaignId);
                    $processedCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    Log::error('Error procesando crédito', [
                        'sync_id' => $credito->sync_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Sincronización completada',
                'processed' => $processedCount,
                'errors' => $errorCount
            ]);

        } catch (Exception $e) {
            Log::error('Error en sincronización de pagos', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error en la sincronización',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getCreditsToSync()
    {
        return DB::table('syncs')->where('fecha_proceso', '>=', self::MINIMUM_DATE)->get();
    }

    /**
     * Obtiene el ID de la campaña activa de tipo API.
     *
     * @return int
     */
    private function getActiveCampaignId(): int
    {
        $campaign = DB::table('campains')
            ->where('type_assign', self::API_TYPE)
            ->where('state', self::ACTIVE_STATE)
            ->first();

        return $campaign ? $campaign->id : 0;
    }

    /**
     * Procesa los pagos de un crédito específico.
     *
     * @param object $credito
     * @param int $campaignId
     * @return void
     * @throws Exception
     */
    private function processCreditPayments($credito, int $campaignId): void
    {
        $payments = $this->fetchPaymentsFromApi($credito->sync_id);

        if ($payments === null) {
            Log::info('No se encontraron pagos', ['sync_id' => $credito->sync_id]);
            return;
        }

        foreach ($payments as $payment) {
            $this->saveOrUpdatePayment($payment, $campaignId);
        }
    }

    /**
     * Obtiene los pagos desde la API externa.
     *
     * @param string $syncId
     * @return array|null
     * @throws Exception
     */
    private function fetchPaymentsFromApi(string $syncId): ?array
    {
        try {
            $response = Http::asForm()->post(env('FACES_PAYS_DEV'), [
                'credNumeroOperacion' => $syncId,
                'credFechaConsulta' => date('d/m/Y', time() - 18000)
            ]);

            if (!$response->successful()) {
                throw new Exception('Error en la respuesta de la API');
            }

            $data = $response->json();
            
            return $data['ListaPagosSEFIL'] ?? null;

        } catch (Exception $e) {
            Log::error('Error consultando API de pagos', [
                'sync_id' => $syncId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Guarda o actualiza un pago en la base de datos.
     *
     * @param object $payment
     * @param int $campaignId
     * @return void
     */
    private function saveOrUpdatePayment($payment, int $campaignId): void
    {
        $existingPayment = DB::table('collection_payments')
            ->where('sync_id', $payment->sync_id)
            ->where('fee_id', $payment->fee_id)
            ->where('payment_id', $payment->payment_id)
            ->first();

        $paymentData = $this->preparePaymentData($payment, $campaignId, $existingPayment !== null);

        if ($existingPayment) {
            DB::table('collection_payments')
                ->where('sync_id', $payment->sync_id)
                ->where('fee_id', $payment->fee_id)
                ->where('payment_id', $payment->payment_id)
                ->update($paymentData);
        } else {
            DB::table('collection_payments')->insert($paymentData);
        }
    }

    /**
     * Prepara los datos del pago para guardar en la base de datos.
     *
     * @param object $payment
     * @param int $campaignId
     * @param bool $isUpdate
     * @return array
     */
    private function preparePaymentData($payment, int $campaignId, bool $isUpdate): array
    {
        return [
            'sync_id' => $payment->sync_id,
            'fee_id' => $payment->fee_id,
            'payment_id' => $payment->payment_id,
            'payment_type' => $payment->payment_type,
            'payment_value' => $payment->payment_value,
            'payment_date' => $payment->payment_date,
            'capital' => $payment->capital,
            'interes' => $payment->interes,
            'mora' => $payment->interes_mora,
            'otros' => $payment->otros,
            'status' => $isUpdate ? 'UPDATED' : 'NEW',
            'campaing' => $campaignId
        ];
    }

    public function getContacts(string $credito): ?array
    {
        try {
            $response = Http::asForm()->post(
                'http://10.10.0.5:81/api/SBK_Sefil/ConsultarContactosSEFIL_V2',
                ['credNumeroOperacion' => $credito]
            );

            if (!$response->successful()) {
                Log::warning('Error al consultar contactos', [
                    'credito' => $credito,
                    'status' => $response->status()
                ]);
                return [];
            }

            $data = $response->json();
            
            if (isset($data['ListaContactosSEFIL']) && $data['ListaContactosSEFIL'] !== null) {
                return $data['ListaContactosSEFIL'];
            }

            return null;

        } catch (Exception $e) {
            Log::error('Excepción al consultar contactos', [
                'credito' => $credito,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Prepara los datos de un crédito para asignación automática según reglas de negocio.
     *
     * @param object $credito Objeto con datos del crédito
     * @param string $type Tipo de operación: "new" para nuevos créditos, vacío para actualización
     * @return array Datos preparados del crédito con asignación de usuario
     */
    private function prepareAssignmentData(object $credito, string $type): array
    {
        $data = [
            'sync_id' => $credito->sync_id,
            'collection_group' => $credito->collection_group,
            'collection_order' => $credito->collection_order,
            'monthly_fee_amount' => $credito->monthly_fee_amount,
            'total_amount' => $credito->total_amount,
            'paid_fees' => $credito->paid_fees,
            'pending_fees' => $credito->pending_fees,
            'total_fees' => $credito->total_fees,
            'Agencia' => $credito->Agencia,
            'Cod_agencia' => $credito->Cod_agencia,
            'debit' => $credito->debit,
            'collection_state' => $credito->collertion_state,
            'days_past_due' => $credito->days_past_due,
            'payment_date' => $credito->payment_date,
            'award_date' => $credito->award_date,
            'due_date' => $credito->due_date,
            'frequency' => $credito->frequency,
            'valor_org' => $credito->valor_org,
            'saldo_capital' => $credito->saldo_capital,
            'interes' => $credito->interes,
            'mora' => $credito->mora,
            'seguro_desgravamen' => $credito->seguro_desgravamen,
            'gastos_cobranza' => $credito->gastos_cobranza,
            'gastos_judiciales' => $credito->gastos_judiciales,
            'otros_valores' => $credito->otros_valores,
            'status' => 'ACTIVE',
            'nro_tried' => 0,
            'fecha_proceso' => date('Y/m/d H:i:s', time() - 18000),
            'oferta' => $credito->oferta,
            'compromiso' => $credito->compromiso,
            'notificacion' => $credito->notificacion
        ];

        if ($type === 'new') {
            $assignment = $this->assignUserByDaysPastDue((int)$credito->days_past_due);
            $data = array_merge($data, $assignment);
        }

        return $data;
    }

    /**
     * Asigna usuario según días de mora.
     *
     * @param int $daysPastDue Días de mora del crédito
     * @return array Array con user_id, tray y status_management
     */
    private function assignUserByDaysPastDue(int $daysPastDue): array
    {
        $assignment = [
            'tray' => 'PENDIENTE',
            'status_management' => 'PENDIENTE'
        ];

        if ($daysPastDue === 0) {
            $assignment['user_id'] = 9;
        } elseif ($daysPastDue === 2) {
            $assignment['user_id'] = 15;
        } elseif ($daysPastDue > 2 && $daysPastDue < 16) {
            $assignment['user_id'] = 0;
        } else { // >= 16
            $assignment['user_id'] = 15;
        }

        return $assignment;
    }

    /**
     * Ejecuta la sincronización completa de créditos desde la API externa.
     *
     * @return array Array con status y mensaje del resultado
     */
    public function exec_sync(): array
    {
        Log::info('Iniciando sincronización de créditos');

        try {
            $creditos = $this->fetchCreditsFromApi();
            
            if ($creditos === null) {
                return $this->handleNullCreditsResponse();
            }

            $campaign = $this->getActiveCampaignRecord();
            if (!$campaign) {
                return ['status' => 400, 'message' => 'No hay campaña activa'];
            }

            $syncStats = $this->initializeSyncProcess($campaign, count($creditos));
            
            $this->deactivateAllCredits();
            
            $processedCount = $this->processCreditsSync($creditos, $syncStats);
            
            $this->distributeCreditsToAgents();
            
            $this->finalizeSyncProcess($syncStats['record_id'], $processedCount);

            return [
                'status' => 200,
                'message' => 'Sincronización correcta',
                'processed' => $processedCount['total'],
                'new' => $processedCount['new']
            ];

        } catch (Exception $e) {
            Log::error('Error en exec_sync', ['error' => $e->getMessage()]);
            $this->handleSyncError($e);
            
            return [
                'status' => 400,
                'message' => 'Error en sincronización',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene los créditos desde la API externa.
     *
     * @return array|null Array de créditos o null si hay error
     */
    private function fetchCreditsFromApi(): ?array
    {
        try {
            $response = Http::asForm()->post(
                'http://10.10.0.5:81/api/SBK_Sefil/ConsultarCreditosSEFIL_V2',
                ['credFechaConsulta' => date('d/m/Y', time() - 18000)]
            );

            if (!$response->successful()) {
                Log::error('Error en respuesta API de créditos', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            return $data['ListaCreditosSEFIL'] ?? null;

        } catch (Exception $e) {
            Log::error('Excepción al consultar créditos', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Obtiene el registro de la campaña activa.
     *
     * @return object|null Objeto de campaña o null
     */
    private function getActiveCampaignRecord(): ?object
    {
        return DB::table('campains')
            ->where('type_assign', self::API_TYPE)
            ->where('state', self::ACTIVE_STATE)
            ->first();
    }

    /**
     * Inicializa el proceso de sincronización.
     *
     * @param object $campaign Campaña activa
     * @param int $creditsCount Total de créditos a procesar
     * @return array Array con información del registro de sync
     */
    private function initializeSyncProcess(object $campaign, int $creditsCount): array
    {
        $existingRecord = DB::table('regsyncs')
            ->where('state', 'INPROCESS')
            ->where('cod_sync', $campaign->cod_sync)
            ->first();

        if ($existingRecord) {
            DB::table('regsyncs')
                ->where('id', $existingRecord->id)
                ->update([
                    'nro_credits' => $creditsCount,
                    'nro_syncs' => 0,
                    'observation' => 'INPROCESS',
                    'state' => 'INPROCESS'
                ]);

            return ['record_id' => $existingRecord->id, 'cod_sync' => $campaign->cod_sync];
        }

        $recordId = DB::table('regsyncs')->insertGetId([
            'daily' => date('Y/m/d', time() - 18000),
            'nro_credits' => $creditsCount,
            'nro_syncs' => 0,
            'nro_credits_new' => 0,
            'observation' => 'INPROCESS',
            'state' => 'INPROCESS',
            'cod_sync' => $campaign->cod_sync
        ]);

        return ['record_id' => $recordId, 'cod_sync' => $campaign->cod_sync];
    }

    /**
     * Desactiva todos los créditos existentes.
     */
    private function deactivateAllCredits(): void
    {
        DB::table('syncs')->update(['status' => 'INACTIVE']);
    }

    /**
     * Procesa la sincronización de todos los créditos.
     *
     * @param array $creditos Array de créditos a procesar
     * @param array $syncStats Estadísticas de sincronización
     * @return array Array con contadores de procesamiento
     */
    private function processCreditsSync(array $creditos, array $syncStats): array
    {
        $count = ['total' => 0, 'new' => 0];
        $currentDay = (int)date('d', time() - 18000);

        foreach ($creditos as $credito) {
            try {
                $this->syncCreditContacts($credito->sync_id, $currentDay);
                
                $creditExists = DB::table('syncs')
                    ->where('sync_id', $credito->sync_id)
                    ->exists();

                if ($creditExists) {
                    $data = $this->prepareAssignmentData($credito, '');
                    DB::table('syncs')->where('sync_id', $credito->sync_id)->update($data);
                } else {
                    $data = $this->prepareAssignmentData($credito, 'new');
                    DB::table('syncs')->insert($data);
                    $count['new']++;
                }

                $count['total']++;

            } catch (Exception $e) {
                Log::error('Error procesando crédito en sync', [
                    'sync_id' => $credito->sync_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $count;
    }

    /**
     * Sincroniza los contactos de un crédito.
     *
     * @param string $syncId ID del crédito
     * @param int $currentDay Día actual del mes
     */
    private function syncCreditContacts(string $syncId, int $currentDay): void
    {
        $existingContacts = DB::table('contactosyncs')
            ->where('sync_id', $syncId)
            ->exists();

        if (!$existingContacts || $currentDay === 1) {
            $contacts = $this->getContacts($syncId);

            if (!empty($contacts)) {
                foreach ($contacts as $contact) {
                    $this->saveOrUpdateContact($contact, $syncId, $currentDay);
                }
            }
        }
    }

    /**
     * Guarda o actualiza un contacto.
     *
     * @param object $contact Objeto contacto
     * @param string $syncId ID del crédito
     * @param int $currentDay Día actual
     */
    private function saveOrUpdateContact(object $contact, string $syncId, int $currentDay): void
    {
        $existingContact = DB::table('contactosyncs')
            ->where('documento', $contact->documento)
            ->where('sync_id', $syncId)
            ->exists();

        $contactData = [
            'sync_id' => $contact->sync_id,
            'sync_client_id' => $contact->sync_client_id,
            'fullName' => $contact->fullName,
            'type' => $contact->type,
            'documento' => $contact->documento,
            'sexo' => $contact->Sexo,
            'estado_civil' => $contact->Estado_civil,
            'sector_economico' => $contact->sector_economico,
            'mobile_phones' => $contact->mobile_phones,
            'landline_phones' => $contact->landline_phones,
            'email' => $contact->email,
            'direccion_domicilio' => json_encode($contact->direccion_domicilio),
            'direccion_trabajo' => json_encode($contact->direccion_trabajo)
        ];

        if ($existingContact && $currentDay === 1) {
            DB::table('contactosyncs')
                ->where('documento', $contact->documento)
                ->where('sync_id', $syncId)
                ->update($contactData);
        } elseif (!$existingContact) {
            DB::table('contactosyncs')->insert($contactData);
        }
    }

    /**
     * Distribuye créditos entre agentes de manera aleatoria.
     */
    private function distributeCreditsToAgents(): void
    {
        $targetAgencies = [
            'CATACOCHA', 'PALANDA', 'CARIAMANGA', 'ZAMORA', 'ZUMBA', 'PIÑAS',
            'CELICA', 'CATAMAYO', 'MALACATOS', 'SANTA ROSA', 'OFICINA LAS PITAS',
            'OFICINA CENTRO', 'OFICINA NORTE', 'SAN MIGUEL DE LOS BANCOS',
            'MILAGRO', 'SANTO DOMINGO', 'EL CARMEN', 'CAYAMBE', 'PASAJE',
            'TUMBACO', 'LA TRONCAL', 'AMAGUAÑA', 'NARANJAL', 'QUINCHE', 'QUININDE'
        ];

        $unassignedCount = DB::table('syncs')
            ->where('user_id', 0)
            ->where('status', 'ACTIVE')
            ->whereIn('Agencia', $targetAgencies)
            ->count();

        $agents = [22];
        $creditsPerAgent = $unassignedCount;

        foreach ($agents as $agentId) {
            DB::table('syncs')
                ->where('user_id', 0)
                ->where('status', 'ACTIVE')
                ->whereIn('Agencia', $targetAgencies)
                ->inRandomOrder()
                ->limit($creditsPerAgent)
                ->update(['user_id' => $agentId]);
        }
    }

    /**
     * Finaliza el proceso de sincronización.
     *
     * @param int $recordId ID del registro de sync
     * @param array $counts Contadores de procesamiento
     */
    private function finalizeSyncProcess(int $recordId, array $counts): void
    {
        DB::table('regsyncs')
            ->where('id', $recordId)
            ->update([
                'state' => 'SYNC',
                'observation' => 'PROCESS FINISH',
                'nro_credits_new' => $counts['new'],
                'nro_syncs' => $counts['total']
            ]);
    }

    /**
     * Maneja la respuesta cuando la lista de créditos es null.
     *
     * @return array Array con status y mensaje de error
     */
    private function handleNullCreditsResponse(): array
    {
        $campaign = $this->getActiveCampaignRecord();

        if ($campaign) {
            $existingRecord = DB::table('regsyncs')
                ->where('state', 'INPROCESS')
                ->where('cod_sync', $campaign->cod_sync)
                ->first();

            $errorData = [
                'nro_credits' => 0,
                'nro_syncs' => 0,
                'observation' => 'LLEGO UN STREAM NULO - ERROR FACES',
                'state' => 'ERROR - NULL'
            ];

            if ($existingRecord) {
                DB::table('regsyncs')->where('id', $existingRecord->id)->update($errorData);
            } else {
                DB::table('regsyncs')->insert(array_merge($errorData, [
                    'daily' => date('Y/m/d', time() - 18000),
                    'nro_credits_new' => 0,
                    'cod_sync' => $campaign->cod_sync
                ]));
            }
        }

        return [
            'status' => 400,
            'message' => 'Se ha recibido Lista de créditos NULL'
        ];
    }

    /**
     * Maneja errores durante la sincronización.
     *
     * @param Exception $exception Excepción capturada
     */
    private function handleSyncError(Exception $exception): void
    {
        $campaign = $this->getActiveCampaignRecord();

        if (!$campaign) {
            return;
        }

        $existingRecord = DB::table('regsyncs')
            ->where('state', 'INPROCESS')
            ->where('cod_sync', $campaign->cod_sync)
            ->first();

        if ($existingRecord) {
            DB::table('regsyncs')
                ->where('id', $existingRecord->id)
                ->update([
                    'observation' => 'STREAM FAIL: ' . $exception->getMessage(),
                    'state' => 'STREAM FAIL'
                ]);
        } else {
            DB::table('regsyncs')->insert([
                'daily' => date('Y/m/d', time() - 18000),
                'nro_credits' => 0,
                'nro_syncs' => 0,
                'nro_credits_new' => 0,
                'observation' => 'SYNC CREATE ERROR: ' . $exception->getMessage(),
                'state' => 'ERROR',
                'cod_sync' => $campaign->cod_sync
            ]);
        }
    }
}
