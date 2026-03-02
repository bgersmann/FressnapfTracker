<?php

declare(strict_types=1);

// CLASS FressnapfTracker
class FressnapfTracker extends IPSModuleStrict
{
    private $baseAuthUrl  = "https://user.iot-pet-tracking.cloud/api/app/v1";
    private $baseUrl  = "https://itsmybike.cloud/api/pet_tracker/v2";
    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     *
     * @return void
     */
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterAttributeString("userID", "");
        $this->RegisterAttributeString("authToken", "");
        $this->RegisterAttributeString("deviceToken", "");
        $this->RegisterAttributeString("serialnumber", "");
        $this->RegisterAttributeString("trackBuffer", "[]");
        $this->RegisterAttributeInteger("lastTrackEnd", 0);
        $this->RegisterAttributeInteger("dogIsAway", 0);
        $this->RegisterAttributeInteger("trackStart", 0);
        $this->RegisterPropertyString("phoneNumber", "+49");
        $this->RegisterPropertyString("smsCode", "123456");
        $this->RegisterPropertyString("TrackerID", "");
        $this->RegisterPropertyBoolean("active", false);
        $this->RegisterPropertyBoolean("trackRecording", false);
        $this->RegisterPropertyInteger("homeRadius", 50);
        $this->RegisterPropertyInteger("homeLeaveMargin", 20);
        $this->RegisterPropertyFloat("maxWalkSpeedKmh", 25.0);
        $this->RegisterPropertyInteger("trackLookbackHours", 6);
        $this->RegisterPropertyInteger( 'Timer', 60 );
        $this->RegisterTimer( 'Collect Data', 0, "FRT_getDeviceData(\$_IPS['TARGET']);" );
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     *
     * @return void
     */
    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     *
     * @return void
     */
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $devices = $this->GetBuffer("devices");
        if ($devices=="") {
            $this->SendDebug("FRT", "Keine Geräte im Buffer gefunden.", 0);
        } else {
            //array nach token (value) durchsuchenen und serialnumber (caption) in Attribut speichern
            $devicesArray = json_decode($devices, true);
            foreach ($devicesArray as $device) {
                if ($device['value'] == $this->ReadPropertyString("TrackerID")) {
                    $this->WriteAttributeString("serialnumber", $device['caption']);
                    $this->WriteAttributeString("deviceToken", $device['value']);
                    $this->SendDebug("FRT", "Serialnumber " . $device['caption'] . " für TrackerID " . $device['value'] . " gespeichert.", 0);
                }
            }
        }
        if ($this->ReadPropertyBoolean('active')) {
            $TimerMS = $this->ReadPropertyInteger( 'Timer' ) * 1000;
            if ( 0 == $TimerMS )
                {
                    $this->SetStatus( 104 );
                } else {
                    $this->SetTimerInterval( 'Collect Data', $TimerMS );
                    $this->SetStatus( 102 );
                    $this->getDeviceData();
                }               
        } else {
            $this->SetStatus(104);
        }

        if ($this->ReadPropertyBoolean('trackRecording')) {
            $this->MaintainVariable('LastDogTrack', $this->Translate('Letzte Hunderunde'), 3, '~TextBox', 60, true);
            $this->MaintainVariable('DogIsAway', $this->Translate('Hund unterwegs'), 0, '', 65, true);
            $this->SetValue('DogIsAway', (bool)$this->ReadAttributeInteger('dogIsAway'));
        } else {
            $this->MaintainVariable('LastDogTrack', $this->Translate('Letzte Hunderunde'), 3, '~TextBox', 60, false);
            $this->MaintainVariable('DogIsAway', $this->Translate('Hund unterwegs'), 0, '', 65, false);
            $this->WriteAttributeString('trackBuffer', '[]');
            $this->WriteAttributeInteger('lastTrackEnd', 0);
            $this->WriteAttributeInteger('dogIsAway', 0);
            $this->WriteAttributeInteger('trackStart', 0);
        }
    }

    public function authenticateSMSCode(): bool
    {
        $userID = $this->ReadAttributeString("userID");
        $smsCode = $this->ReadPropertyString("smsCode");
        if ($userID == "" || $smsCode == "") {
            $this->SendDebug("FRT", "Keine Authentifizierungsinformationen gefunden. Bitte SMS Code anfordern, eintragen und erneut authentifizieren.", 0);
            return false;
        }
        $this->SendDebug("FRT", "authentitcate SMS gestartet", 0); 
        $url = $this->baseAuthUrl . "/users/verify_phone_number";
        $payload = json_encode([
            "user" => [
                "id" => $userID,
                "smscode"    => $smsCode,
                "user_token" => [
                        "push_token" => "",
                        "app_version" => "2.9.0_11",
                        "app_platform" => "android",
                        "platform_version"=> 30,
                        "phone_name"=> "fressnapftracker",
                ],
            ],
        ]);
        $this->SendDebug("FRT", "Payload: " . $payload, 0);

        $response = $this->CallFressnapfAPI($url, $payload,"POST");
    
        if (isset($response['user_token']['access_token'])) {
            $this->WriteAttributeString("authToken", $response['user_token']['access_token']);
            $this->SendDebug("FRT", "Authentifizierung erfolgreich. Auth Token gespeichert.", 0);
             $this->SetStatus(102);
        } else {
            $this->SendDebug("FRT", "Fehler bei der Authentifizierung: " . json_encode($response), 0);
        }
        return isset($response['user_token']['access_token']);
    }

    public function getSmsCode(): bool
    {
        $phoneNumber = $this->ReadPropertyString("phoneNumber");
        if ($phoneNumber == "") {
            $this->SendDebug("FRT", "Keine Telefonnummer angegeben. Bitte Telefonnummer eingeben und SMS Code anfordern.", 0);
            return false;
        }
        $this->SendDebug("FRT", "Get SMS gestartet", 0);
        $url = $this->baseAuthUrl . "/users/request_sms_code";
        $payload = json_encode([
            "user" => [
                "phone" => $phoneNumber,
                "locale" => "de"
            ],
            "tracker_service" => "fressnapf"
        ]);

        $response = $this->CallFressnapfAPI($url, $payload,"POST");
    
        if (isset($response['id'])) {
            $this->WriteAttributeString("userID", $response['id']);
            $this->SendDebug("FRT", "SMS wurde an " . $phoneNumber . " gesendet. Code oben eintragen und Skript erneut starten.", 0);
            $this->SetStatus(301);
        } else {
            $this->SendDebug("FRT", "Fehler beim Senden: " . json_encode($response), 0);
        }
        return isset($response['id']);
    }

    private function CallFressnapfAPI($url, $payload,$type):array {    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: Fressnapf/2.2.0 (iPhone; iOS 16.0; Scale/3.00)',
            'Authorization: Bearer FgvX_UJ7!BQRLU((1WhwFoOp'
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);    
        if (curl_errno($ch)) {
            $this->SendDebug("FRT", "Fehler: " . curl_error($ch), 0);
        } else {
            $this->SendDebug("FRT", "Status Code: " . $http_code, 0);
            $this->SendDebug("FRT", "URL: " . $url, 0);
            $this->SendDebug("FRT", "Response: " . $result, 0);
        }
        curl_close($ch);
        if ($http_code >= 200 && $http_code < 300) {
            $this->SendDebug("FRT", "API-Aufruf erfolgreich.", 0);
        } else {
            $this->SendDebug("FRT", "API-Aufruf fehlgeschlagen mit Status Code: " . $http_code, 0);
        }
        if ($result === null || $result === false) {
            $this->SendDebug("FRT", "Keine Antwort erhalten.", 0);
            return [];
        }

        $decoded = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $this->SendDebug("FRT", "Antwort konnte nicht verarbeitet werden: " . json_last_error_msg(), 0);
            return [];
        }

        return $decoded;
    }

    public function getDevices(): array
    {
        $userID=$this->ReadAttributeString("userID");
        $authToken=$this->ReadAttributeString("authToken");
        if ($userID == "" || $authToken == "") {
            $this->SendDebug("FRT", "Keine Authentifizierungsinformationen gefunden. Bitte SMS Code anfordern, eintragen und authentifizieren.", 0);
            return [];
        }
        $url = $this->baseAuthUrl . "/devices/";
        $payload = json_encode([
                "user_id" => $userID,
                "user_access_token" => $authToken
            ]);
        $devices = $this->CallFressnapfAPI($url, $payload,"GET");
        if (isset($devices)) {
            foreach ( $devices as $device ) {
                    $value[] = [
                        'caption'=>$device['serialnumber'],
                        'value'=> isset( $device['token'] ) ? $device['token']: 'missing'
                    ];
                }
        } else {
            $value = [
                [
                    'caption'=>'No devices found',
                    'value'=>''
                ]
            ];
        }
        $this->SetBuffer("devices", json_encode($value)); 
        $this->UpdateFormField("TrackerID", "options", json_encode($value));
        return $value;
    }
    public function getDeviceData(): bool
    {
        $deviceID = $this->ReadAttributeString("serialnumber");
        $deviceToken= $this->ReadAttributeString("deviceToken");
        if ($deviceID == "" || $deviceToken == "") {
            $this->SendDebug("FRT", "Keine Geräteinformationen gefunden. Bitte TrackerID auswählen und speichern.", 0);
            return false;
        }
        $url = $this->baseUrl . "/devices/".$deviceID;

        $payload = json_encode([
                "devicetoken" => $deviceToken
            ]);
        $response = $this->CallFressnapfAPI($url, $payload,"GET");
        $this->SendDebug("FRT", json_encode($response), 0);
        if (isset($response['position']['lat'])) {
            $this->MaintainVariable( 'Latitude', $this->Translate( 'Latitude' ), 2, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'USAGE_TYPE'=> 0 ,'ICON'=> 'location-crosshairs'], 10, 1 );
            $this->SetValue('Latitude',$response['position']['lat']);    
        }
        if (isset($response['position']['lng'])) {
            $this->MaintainVariable( 'Longitude', $this->Translate( 'Longitude' ), 2, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'USAGE_TYPE'=> 0 ,'ICON'=> 'location-crosshairs'], 20, 1 );
            $this->SetValue('Longitude',$response['position']['lng']);    
        }
        if (isset($response['position']['accuracy'])) {
            $this->MaintainVariable( 'Accuracy', $this->Translate( 'Accuracy' ), 1, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'USAGE_TYPE'=> 0 ,'ICON'=> 'location-crosshairs'], 30, 1 );
            $this->SetValue('Accuracy',$response['position']['accuracy']);    
        }
        if (isset($response['position']['sampled_at'])) {
            $this->MaintainVariable( 'SampledAt', $this->Translate( 'Sampled At' ), 3, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'USAGE_TYPE'=> 0 ,'ICON'=> 'location-crosshairs'], 40, 1 );
            $this->SetValue('SampledAt',$response['position']['sampled_at']);    
        }
        if (isset($response['battery'])) {
            $this->MaintainVariable( 'Battery', $this->Translate( 'Battery' ), 1, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'USAGE_TYPE'=> 0 ,'ICON'=> 'battery-full', 'SUFFIX'=> '%'], 50, 1 );
            $this->SetValue('Battery',$response['battery']);    
        }
        if (isset($response['last_seen'])) {
            $this->MaintainVariable( 'LastSeen', $this->Translate( 'Last Seen' ), 3, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'USAGE_TYPE'=> 0 ,'ICON'=> 'location-crosshairs'], 40, 1 );
            $this->SetValue('LastSeen',$response['last_seen']);    
        }
        if (isset($response['name'])) {
            $this->MaintainVariable( 'Name', $this->Translate( 'Name' ), 3, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'USAGE_TYPE'=> 0 ,'ICON'=> 'circle-info'], 5, 1 );
            $this->SetValue('Name',$response['name']); 
            $this->SetSummary($response['name']);    
        }
        if (isset($response['charging'])) {
            $this->MaintainVariable( 'Charging', $this->Translate( 'Charging' ), 0, [ 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON'=> 'battery-bolt', 'OPTIONS'=>'[{"ColorDisplay":1692672,"Value":false,"Caption":"Not Charging","IconValue":"","IconActive":false,"ColorActive":true,"ColorValue":1692672,"Color":-1},{"ColorDisplay":16711680,"Value":true,"Caption":"Charging...","IconValue":"","IconActive":false,"ColorActive":true,"ColorValue":16711680,"Color":-1}]' ], 52, 1 );
			
            $this->SetValue('Charging',$response['charging']);    
        }

        $this->handleTrackRecording($response);
        return true;
    }

    // IPSModuleStrict
    public function GetConfigurationForm(): string {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $this->getDevices();
        $Bufferdata = $this->GetBuffer("devices");
		if ($Bufferdata=="") {
		    $arrayOptions[] = array( 'caption' => 'No tracker found', 'value' => '' );
		} else {
		    $arrayOptions=json_decode($Bufferdata, true);
		}
        $form['elements'][6]['options'] = $arrayOptions;
        return json_encode($form);
    }

    private function handleTrackRecording(array $response): void
    {
        if (!$this->ReadPropertyBoolean('trackRecording')) {
            return;
        }

        if (!isset($response['position']['lat'], $response['position']['lng'])) {
            return;
        }

        $homeCoordinates = $this->getHomeCoordinates();
        if ($homeCoordinates === null) {
            return;
        }
        [$homeLat, $homeLon] = $homeCoordinates;

        $timestamp = $this->resolveTimestamp($response);
        if ($timestamp === null) {
            return;
        }

        $currentLat = (float)$response['position']['lat'];
        $currentLon = (float)$response['position']['lng'];
        $distanceHome = $this->calculateCoordinateDistance($currentLat, $currentLon, $homeLat, $homeLon);
        $radius = max(1, (int)$this->ReadPropertyInteger('homeRadius'));
        $leaveMargin = max(0, (int)$this->ReadPropertyInteger('homeLeaveMargin'));
        $isAway = (bool)$this->ReadAttributeInteger('dogIsAway');

        if (!$isAway && $distanceHome > ($radius + $leaveMargin)) {
            $this->SendDebug('FRT', 'Hunderunde gestartet (außerhalb des Radius).', 0);
            $this->setDogAwayState(true, $timestamp);
            return;
        }

        if ($isAway && $distanceHome < $radius) {
            $this->SendDebug('FRT', 'Hund ist zurück im Radius – Runde wird ausgewertet.', 0);
            $this->finalizeDogRound($timestamp);
            $this->setDogAwayState(false, $timestamp);
        }
    }

    private function resolveTimestamp(array $response): ?int
    {
        $candidates = [];
        if (isset($response['position']['sampled_at'])) {
            $candidates[] = $response['position']['sampled_at'];
        }
        if (isset($response['last_seen'])) {
            $candidates[] = $response['last_seen'];
        }

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $value = (int)$candidate;
                if ($value > 0) {
                    return $value;
                }
            }
            $parsed = strtotime((string)$candidate);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return time();
    }
    private function setDogAwayState(bool $state, int $timestamp): void
    {
        $this->WriteAttributeInteger('dogIsAway', $state ? 1 : 0);
        $this->WriteAttributeInteger('trackStart', $state ? $timestamp : 0);

        $variableID = @$this->GetIDForIdent('DogIsAway');
        if ($variableID) {
            $this->SetValue('DogIsAway', $state);
        }
    }

    private function finalizeDogRound(int $endTimestamp): void
    {
        $archiveID = $this->resolveArchiveControlID();
        if ($archiveID === 0) {
            $this->SendDebug('FRT', 'Kein Archive Control verfügbar – keine Runde gespeichert.', 0);
            return;
        }

        $latVarID = @$this->GetIDForIdent('Latitude');
        $lonVarID = @$this->GetIDForIdent('Longitude');
        if (!$latVarID || !$lonVarID) {
            $this->SendDebug('FRT', 'Latitude/Longitude Variablen nicht gefunden.', 0);
            return;
        }

        $hours = max(1, (int)$this->ReadPropertyInteger('trackLookbackHours'));
        $lookbackSeconds = $hours * 3600;
        $startTimestamp = (int)$this->ReadAttributeInteger('trackStart');
        if ($startTimestamp <= 0) {
            $startTimestamp = $endTimestamp - $lookbackSeconds;
        }
        $startTimestamp = max($endTimestamp - $lookbackSeconds, $startTimestamp - 60);

        $latValues = AC_GetLoggedValues($archiveID, $latVarID, $startTimestamp, $endTimestamp, 0);
        $lonValues = AC_GetLoggedValues($archiveID, $lonVarID, $startTimestamp, $endTimestamp, 0);
        if (empty($latValues) || empty($lonValues)) {
            $this->SendDebug('FRT', 'Keine archivierten Koordinaten für die Auswertung gefunden.', 0);
            return;
        }

        $route = $this->mergeRouteData($latValues, $lonValues);
        if (count($route) < 2) {
            $this->SendDebug('FRT', 'Gefilterte Route enthält zu wenige Punkte.', 0);
            return;
        }

        usort($route, fn ($a, $b) => $a['t'] <=> $b['t']);
        $json = json_encode($route);
        $trackVarID = @$this->GetIDForIdent('LastDogTrack');
        if ($trackVarID) {
            $this->SetValue('LastDogTrack', $json);
        }
        $this->WriteAttributeString('trackBuffer', $json);
        $lastPoint = $route[count($route) - 1];
        $this->WriteAttributeInteger('lastTrackEnd', (int)$lastPoint['t']);
    }

    private function resolveArchiveControlID(): int
    {
        $list = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return $list[0] ?? 0;
    }

    private function mergeRouteData(array $latValues, array $lonValues): array
    {
        if (empty($latValues) || empty($lonValues)) {
            return [];
        }

        $tolerance = 2; // seconds
        $maxSpeed = max(1.0, (float)$this->ReadPropertyFloat('maxWalkSpeedKmh'));
        $cleaned = [];
        $lastLat = null;
        $lastLon = null;
        $lastTime = null;

        $lonIndex = 0;
        $lonCount = count($lonValues);

        foreach ($latValues as $latPoint) {
            $latTime = (int)$latPoint['TimeStamp'];
            while ($lonIndex < $lonCount && (int)$lonValues[$lonIndex]['TimeStamp'] < ($latTime - $tolerance)) {
                $lonIndex++;
            }

            if ($lonIndex >= $lonCount) {
                break;
            }

            $lonPoint = $lonValues[$lonIndex];
            if (abs((int)$lonPoint['TimeStamp'] - $latTime) > $tolerance) {
                continue;
            }

            $currentLat = (float)$latPoint['Value'];
            $currentLon = (float)$lonPoint['Value'];
            $currentTime = $latTime;

            if ($lastLat !== null) {
                $distance = $this->calculateCoordinateDistance($lastLat, $lastLon, $currentLat, $currentLon);
                $timeDiffHours = ($currentTime - $lastTime) / 3600;
                $speed = ($timeDiffHours > 0) ? ($distance / 1000) / $timeDiffHours : 0.0;
                if ($speed > $maxSpeed) {
                    continue;
                }
            }

            $cleaned[] = [
                'lat' => $currentLat,
                'lon' => $currentLon,
                't'   => $currentTime
            ];

            $lastLat = $currentLat;
            $lastLon = $currentLon;
            $lastTime = $currentTime;
        }

        return $cleaned;
    }

    private function getHomeCoordinates(): ?array
    {
        $locationID = IPS_GetInstanceListByModuleID('{45E97A63-F870-408A-B259-2933F7EABF74}')[0] ?? 0;
        if ($locationID === 0) {
            $this->SendDebug('FRT', 'Keine Location Control Instanz gefunden.', 0);
            return null;
        }

        $locationRaw = IPS_GetProperty($locationID, 'Location');
        $this->SendDebug('FRT', 'Location:'. $locationRaw, 0);
        if (is_string($locationRaw) && $locationRaw !== '') {
            $decoded = json_decode($locationRaw, true);
            if (is_array($decoded) && isset($decoded['latitude']) && isset($decoded['longitude'])) {
                return [(float)$decoded['latitude'], (float)$decoded['longitude']];
            }
        }

        return null;
    }

    private function calculateCoordinateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $lonFrom = deg2rad($lon1);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2)
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) * sin($lonDelta / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}