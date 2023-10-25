function cpanel_GetSupportedApplicationLinks()
{
    $appLinksData = file_get_contents(ROOTDIR . "/modules/servers/cpanel/data/application_links.json");
    $appLinks = json_decode($appLinksData, true);
    if (array_key_exists("supportedApplicationLinks", $appLinks)) {
        return $appLinks["supportedApplicationLinks"];
    }
    return [];
}
function cpanel_GetRemovedApplicationLinks()
{
    $appLinksData = file_get_contents(ROOTDIR . "/modules/servers/cpanel/data/application_links.json");
    $appLinks = json_decode($appLinksData, true);
    if (array_key_exists("disabledApplicationLinks", $appLinks)) {
        return $appLinks["disabledApplicationLinks"];
    }
    return [];
}
function cpanel_IsApplicationLinkingSupportedByServer($params)
{
    try {
        $cpanelResponse = cpanel_jsonrequest($params, "/json-api/applist", "api.version=1");
        $resultCode = isset($cpanelResponse["metadata"]["result"]) ? $cpanelResponse["metadata"]["result"] : 0;
        if (!$resultCode) {
            $resultCode = isset($cpanelResponse["cpanelresult"]["data"]["result"]) ? $cpanelResponse["cpanelresult"]["data"]["result"] : 0;
        }
        if (0 < $resultCode) {
            return ["isSupported" => in_array("create_integration_link", $cpanelResponse["data"]["app"])];
        }
        if (isset($cpanelResponse["cpanelresult"]["error"])) {
            $errorMsg = $cpanelResponse["cpanelresult"]["error"];
        } else {
            if (isset($cpanelResponse["metadata"]["reason"])) {
                $errorMsg = $cpanelResponse["metadata"]["reason"];
            } else {
                $errorMsg = "Server response: " . preg_replace("/([\\d\"]),\"/", "\$1, \"", json_encode($cpanelResponse));
            }
        }
    } catch (WHMCS\Exception $e) {
        $errorMsg = $e->getMessage();
    }
    return ["errorMsg" => $errorMsg];
}
function cpanel_CreateApplicationLink($params)
{
    $systemUrl = $params["systemUrl"];
    $tokenEndpoint = $params["tokenEndpoint"];
    $clientCollection = $params["clientCredentialCollection"];
    $appLinks = $params["appLinks"];
    $stringsToMask = [];
    $commands = [];
    foreach ($clientCollection as $client) {
        $secret = $client->decryptedSecret;
        $identifier = $client->identifier;
        $apiData = ["api.version" => 1, "user" => $client->service->username, "group_id" => "whmcs", "label" => "Billing & Support", "order" => "1"];
        $commands[] = "command=create_integration_group?" . urlencode(http_build_query($apiData));
        foreach ($appLinks as $scopeName => $appLinkParams) {
            $queryParams = ["scope" => "clientarea:sso " . $scopeName, "module_type" => "server", "module" => "cpanel"];
            $fallbackUrl = $appLinkParams["fallback_url"];
            $fallbackUrl .= (strpos($fallbackUrl, "?") ? "&" : "?") . "ssoredirect=1";
            unset($appLinkParams["fallback_url"]);
            $apiData = ["api.version" => 1, "user" => $client->service->username, "subscriber_unique_id" => $identifier, "url" => $systemUrl . $fallbackUrl, "token" => $secret, "autologin_token_url" => $tokenEndpoint . "?" . http_build_query($queryParams)];
            $commands[] = "command=create_integration_link?" . urlencode(http_build_query($apiData + $appLinkParams));
            $stringsToMask[] = urlencode(urlencode($secret));
        }
    }
    $errors = [];
    try {
        $cpanelResponse = cpanel_jsonrequest($params, "/json-api/batch", "api.version=1&" . implode("&", $commands), $stringsToMask);
        if ($cpanelResponse["metadata"]["result"] == 0) {
            foreach ($cpanelResponse["data"]["result"] as $key => $values) {
                if ($values["metadata"]["result"] == 0) {
                    $reasonMsg = isset($values["metadata"]["reason"]) ? $values["metadata"]["reason"] : "";
                    cpanel__adderrortolist($reasonMsg, $errors);
                }
            }
        }
    } catch (Throwable $e) {
        cpanel__adderrortolist($e->getMessage(), $errors);
    }
    return cpanel__formaterrorlist($errors);
}
function cpanel_DeleteApplicationLink($params)
{
    $clientCollection = $params["clientCredentialCollection"];
    $appLinks = $params["appLinks"];
    $commands = [];
    foreach ($clientCollection as $client) {
        $apiData = ["api.version" => 1, "user" => $client->service->username, "group_id" => "whmcs"];
        $commands[] = "command=remove_integration_group?" . urlencode(http_build_query($apiData));
        foreach ($appLinks as $scopeName => $appLinkParams) {
            $apiData = ["api.version" => 1, "user" => $client->service->username, "app" => $appLinkParams["app"]];
            $commands[] = "command=remove_integration_link?" . urlencode(http_build_query($apiData));
        }
    }
    try {
        $cpanelResponse = cpanel_jsonrequest($params, "/json-api/batch", "api.version=1&" . implode("&", $commands));
        $errors = [];
        if ($cpanelResponse["metadata"]["result"] == 0) {
            foreach ($cpanelResponse["data"]["result"] as $key => $values) {
                if ($values["metadata"]["result"] == 0) {
                    $reasonMsg = isset($values["metadata"]["reason"]) ? $values["metadata"]["reason"] : "";
                    cpanel__adderrortolist($reasonMsg, $errors);
                }
            }
        }
    } catch (Throwable $e) {
        cpanel__adderrortolist($e->getMessage(), $errors);
    }
    return cpanel__formaterrorlist($errors);
}
