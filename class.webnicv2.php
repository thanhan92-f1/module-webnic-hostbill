<?php
require_once MAINDIR . "includes" . DS . "libs" . DS . "idn" . DS . "class.idn.php";
class WebNic2 extends DomainModule implements DomainPriceImport, DomainLookupInterface, DomainPremiumInterface, DomainModuleGluerecords
{
    protected $version = "1.250416";
    protected $description = "WebNic domain registrar module";
    protected $modname = "WebNiC v2";
    protected $lang = ["english" => ["source" => "Source", "hide_form" => "Hide forms during transfer", "test" => "Test Mode"]];
    protected $commands = ["Register", "Transfer", "Renew", "ContactInfo", "RegisterNameServers"];
    protected $clientCommands = ["ContactInfo"];
    protected $configuration = ["source" => ["value" => "", "type" => "input", "default" => false], "password" => ["value" => "", "type" => "password", "default" => false], "hide_form" => ["value" => "", "type" => "check", "default" => false], "test" => ["value" => "", "type" => "check", "default" => false]];
    protected $proxy_tlds = ["my", "com.my", "net.my", "org.my", "sg", "com.sg", "asia", "kr", "co.kr", "it", "de", "jp", "id", "co.id", "web.id"];
    protected $proxy_field = "webnicproxy";
    public function checkAvailability()
    {
        $response = $this->restApiV2()->get("/domain/v2/query?domainName=" . $this->name);
        if($response["code"] != "1000" || !$response["data"]["available"]) {
            return false;
        }
        return true;
    }
    public function restApiV2()
    {
        if($this->apiV2) {
            return $this->apiV2;
        }
        if($this->configuration["test"]["value"] == "1") {
            $url = "https://oteapi.webnic.cc";
        } else {
            $url = "https://api.webnic.cc";
        }
        $this->apiV2 = new WebNicRestApiV2($url);
        $this->apiV2->login($this->configuration["source"]["value"], $this->configuration["password"]["value"]);
        return $this->apiV2;
    }
    public function testConnection()
    {
        $url = "reseller/v2/balance";
        $response = $this->restApiV2()->get($url);
        if($response["code"] == "1000") {
            return true;
        }
        return false;
    }
    public function Register()
    {
        $params = $this->prepareMainParametersForRegisterOrTransfer();
        if(!$params) {
            return false;
        }
        if(isset($this->options["ext"][$this->proxy_field]) && in_array($this->options["tld"], $this->proxy_tlds)) {
            $params["addons"]["proxy"] = true;
        }
        $response = $this->restApiV2()->post("domain/v2/register", $params);
        if($response["code"] != "1000") {
            $this->addError($response["error"]["message"]);
            return false;
        }
        $this->addDomain(DOMAIN_STATE_PENDINGREGISTRATIO);
        $this->addInfo("Domain has been successfully registered.");
        return true;
    }
    private function prepareMainParametersForRegisterOrTransfer($transfer = false)
    {
        $this->_prepare_name();
        $domainNameArray = explode(".", $this->name);
        $sld = head($domainNameArray);
        $tld = end($domainNameArray);
        $lookupDomain = [];
        if(!$transfer) {
            $lookupDomain = $this->lookupDomain($sld, $tld);
            if(is_array($lookupDomain) && $lookupDomain["premium"] && !self::arePremiumDomainsAllowed()) {
                $this->addError(self::ERR_PREMIUM_DOMAINS_DISABLED);
                return false;
            }
        }
        $nameservers = $this->prepareNameserversData();
        $contacts = $this->createAllContacts();
        if(!$contacts) {
            return false;
        }
        $registrantAccount = $this->createRegistrantAccount();
        if(!$registrantAccount) {
            return false;
        }
        $params = ["domainName" => $this->name, "domainType" => !$transfer && is_array($lookupDomain) && array_key_exists("premium", $lookupDomain) ? "premium" : "standard", "term" => $this->period, "nameservers" => $nameservers, "registrantUserId" => $registrantAccount];
        foreach ($contacts as $contact) {
            $params[$contact["contactType"] . "ContactId"] = $contact["contactId"];
        }
        foreach (["administrator", "billing", "technical"] as $contactType) {
            if(!array_key_exists($contactType . "ContactId", $params)) {
                $params[$contactType . "ContactId"] = $params["registrantContactId"];
            }
        }
        return $params;
    }
    protected function _prepare_name()
    {
        $sld = rtrim($this->options["sld"], ".");
        $tld = ltrim($this->options["tld"], ".");
        $idn = new IDN();
        $tld = $idn->decode($tld);
        if(!$tld) {
            return NULL;
        }
        $this->name = $sld . "." . $tld;
    }
    public function lookupDomain($sld, $tld, $settings = [])
    {
        $name = rtrim($sld, ".") . "." . ltrim($tld, ".");
        $name = HBLoader::LoadModel("Domains")->getDomainIDN($name);
        $return = ["available" => false];
        $response = $this->restApiV2()->get("domain/v2/query?domainName=" . $name);
        if($response && $response["code"] == "1000") {
            $data = $response["data"];
            if(!$data) {
                return $return;
            }
            $return["available"] = $data["available"];
            if($response["data"]["premium"] && array_key_exists("premiumInfo", $data)) {
                $return["premium"] = ["register" => $data["premiumInfo"]["registerPrice"], "renew" => $data["premiumInfo"]["renewPrice"], "transfer" => $data["premiumInfo"]["transferPrice"], "currency" => $data["premiumInfo"]["currency"]];
            }
        }
        return $return;
    }
    private function prepareNameserversData()
    {
        $nameservers = array_values(array_filter($this->options, function ($value, $key) {
            return preg_match("/^ns\\d+\$/", $key) && $value !== "";
        }, ARRAY_FILTER_USE_BOTH));
        if(empty($nameservers)) {
            $response = $this->restApiV2()->get("/domain/v2/dns/default");
            if($response["code"] != "1000") {
                return false;
            }
            foreach ($response["data"] as $key => $defaultNs) {
                $this->options["ns" . ($key + 1)] = $defaultNs;
            }
            $nameservers = $response["data"];
        }
        return $nameservers;
    }
    private function createAllContacts()
    {
        $registrant = $this->prepareContactData("registrant");
        $admin = $this->prepareContactData("admin");
        $billing = $this->prepareContactData("billing");
        $tech = $this->prepareContactData("tech");
        $createContacts = ["registrant" => $registrant];
        if($registrant !== $admin) {
            $createContacts["administrator"] = $admin;
        }
        if($registrant !== $billing) {
            $createContacts["billing"] = $billing;
        }
        if($registrant !== $tech) {
            $createContacts["technical"] = $tech;
        }
        $contactsResponse = $this->restApiV2()->post("domain/v2/contact/create", $createContacts);
        if($contactsResponse["code"] != "1000") {
            return false;
        }
        return $contactsResponse["data"];
    }
    public function prepareContactData($type = "registrant", $cdata = false)
    {
        if(empty($cdata["email"]) && !empty($this->domain_contacts[$type]["email"])) {
            $cdata = $this->domain_contacts[$type];
        } elseif(empty($cdata["email"])) {
            $cdata = $this->options;
        }
        $phone = Utilities::get_phone_info($cdata["phonenumber"], $cdata["country"]);
        $phone = "+" . $phone["ccode"] . "." . $phone["number"];
        $data = ["category" => isset($cdata["companyname"]) && $cdata["companyname"] != "" ? "organization" : "individual", "company" => $this->utf8escape(empty($cdata["companyname"]) ? $cdata["firstname"] . " " . $cdata["lastname"] : $cdata["companyname"]), "firstName" => $this->utf8escape($cdata["firstname"]), "lastName" => $this->utf8escape($cdata["lastname"]), "address1" => $this->utf8escape($cdata["address1"]), "city" => $this->utf8escape($cdata["city"]), "state" => $this->utf8escape($cdata["state"]), "countryCode" => $cdata["country"], "zip" => $this->utf8escape($cdata["postcode"]), "phoneNumber" => $phone, "email" => $cdata["email"]];
        foreach ($this->options["ext"] as $ckey => $cdata) {
            $data["customFields"][$ckey] = $cdata;
        }
        return $data;
    }
    private function utf8escape($str)
    {
        $str = preg_replace("/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/", "a", $str);
        $str = preg_replace("/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/", "e", $str);
        $str = preg_replace("/(ì|í|ị|ỉ|ĩ)/", "i", $str);
        $str = preg_replace("/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/", "o", $str);
        $str = preg_replace("/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/", "u", $str);
        $str = preg_replace("/(ỳ|ý|ỵ|ỷ|ỹ)/", "y", $str);
        $str = preg_replace("/(đ)/", "d", $str);
        $str = preg_replace("/(À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ)/", "A", $str);
        $str = preg_replace("/(È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ)/", "E", $str);
        $str = preg_replace("/(Ì|Í|Ị|Ỉ|Ĩ)/", "I", $str);
        $str = preg_replace("/(Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ)/", "O", $str);
        $str = preg_replace("/(Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ)/", "U", $str);
        $str = preg_replace("/(Ỳ|Ý|Ỵ|Ỷ|Ỹ)/", "Y", $str);
        $str = preg_replace("/(Đ)/", "D", $str);
        $str = preg_replace("/(ı)/", "i", $str);
        $str = preg_replace("/(ğ)/", "g", $str);
        $str = preg_replace("/(ü)/", "u", $str);
        $str = preg_replace("/(ş)/", "s", $str);
        $str = preg_replace("/(ö)/", "o", $str);
        $str = preg_replace("/(ç)/", "c", $str);
        $str = preg_replace("/(Ğ)/", "G", $str);
        $str = preg_replace("/(Ü)/", "U", $str);
        $str = preg_replace("/(Ş)/", "S", $str);
        $str = preg_replace("/(İ)/", "I", $str);
        $str = preg_replace("/(Ö)/", "O", $str);
        $str = preg_replace("/(Ç)/", "C", $str);
        return $str;
    }
    public function Transfer()
    {
        $this->_prepare_name();
        $transferTypeResponse = $this->restApiV2()->get("domain/v2/query-transfer-type?domainName=" . $this->name);
        if($transferTypeResponse["code"] != "1000") {
            return false;
        }
        if(!$transferTypeResponse["data"] || !$transferTypeResponse["data"]["transferType"]) {
            return false;
        }
        $transferResponse = false;
        if($transferTypeResponse["data"]["transferType"] === "registrar_transfer") {
            $transferResponse = $this->submitRegistrarTransfer();
        } elseif($transferTypeResponse["data"]["transferType"] === "reseller_transfer") {
            $transferResponse = $this->submitResellerTransfer();
        }
        if(!$transferResponse) {
            return false;
        }
        $this->addDomain(DOMAIN_STATE_PENDINGTRANSFER);
        $this->addInfo("Transfer Domain Action succeeded.");
        return true;
    }
    public function Renew()
    {
        $this->_prepare_name();
        $renewResponse = $this->restApiV2()->post("domain/v2/renew", ["domainName" => $this->name, "term" => $this->period]);
        if($renewResponse["code"] !== "1000") {
            if($renewResponse["error"]["message"]) {
                $this->addError($renewResponse["error"]["message"]);
            }
            return false;
        }
        $this->addPeriod();
        $this->addInfo("Domain renewed.");
        return true;
    }
    public function getExtendedAttributes()
    {
        $attributes = [];
        $attributes[] = ["name" => "identificationNumber", "description" => "Identification Number", "type" => "input"];
        $attributes[] = ["name" => "organizationRegistrationNumber", "description" => "Organization Registration Number", "type" => "input"];
        if(substr($this->options["tld"], -2) == "us") {
            $attributes[] = ["name" => "nexus", "description" => "Nexus Category", "type" => "select", "option" => [["value" => "C11", "title" => "US Citizen"], ["value" => "C12", "title" => "Permanent Resident"], ["value" => "C21", "title" => "Business Entity"], ["value" => "C31", "title" => "Foreign Entity"], ["value" => "C32", "title" => "US Based Office"]]];
        }
        if($this->options["tld"][["com.cn" => true, "gov.cn" => true, "net.cn" => true, "org.cn" => true, "cn" => true, "中国" => true, "公司" => true, "网络" => true]]) {
            $attributes[] = ["name" => "organizationType", "description" => "Application Purpose (Organization only)", "type" => "select", "option" => [["value" => "ORG", "title" => "Organization Code Certificate"], ["value" => "YYZZ", "title" => "Business License"], ["value" => "TYDM", "title" => "Certificate for Uniform Social Credit Code"], ["value" => "BDDM", "title" => "Military Code Designation"], ["value" => "JDDWFW", "title" => "Military Paid External Service License"], ["value" => "SYDWFR", "title" => "Public Institution Legal Person Certificate"], ["value" => "SHTTFR", "title" => "Social Organization Legal Person Registration Certificate"], ["value" => "ZJCS", "title" => "eligion Activity Site Registration Certificate"], ["value" => "MBFQY", "title" => "Private Non-Enterprise Entity Registration Certificate"], ["value" => "JJHFR", "title" => "Fund Legal Person Registration Certificate"], ["value" => "LSZY", "title" => "Practicing License of Law Firm"], ["value" => "WGZHWH", "title" => "Registration Certificate of Foreign Cultural Center in China"], ["value" => "WLCZJG", "title" => "Resident Representative Office of Tourism Departments of Foreign Government Approval Registration Certificate"], ["value" => "SFJD", "title" => "Judicial Expertise License"], ["value" => "JWJG", "title" => "Overseas Organization Certificate"], ["value" => "SHFWJG", "title" => "Social Service Agency Registration Certificate"], ["value" => "MBXXBX", "title" => "Private School Permit"], ["value" => "YLJGZY", "title" => "Medical Institution Practicing License"], ["value" => "GZJGZY", "title" => "Notary Organization Practicing License"], ["value" => "BJWSXX", "title" => "Beijing School for Children of Foreign Embassy Staff in China Permit"]]];
            $attributes[] = ["name" => "individualType", "description" => "Individual Type", "type" => "select", "option" => [["value" => "SFZ", "title" => "ID"], ["value" => "HZ", "title" => "Passport"], ["value" => "GAJMTX", "title" => "Exit-Entry Permit for Travelling to and from Hong Kong and Macao"], ["value" => "TWJMTX", "title" => "Travel passes for Taiwan Residents to Enter or Leave the Mainland"], ["value" => "WJLSFZ", "title" => "Foreign Permanent Resident ID Card"], ["value" => "GAJZZ", "title" => "Residence permit for Hong Kong and Macao residents"], ["value" => "TWJZZ", "title" => "Residence permit for Taiwan residents"], ["value" => "JGZ", "title" => "Medical Institution Practicing License"], ["value" => "QT", "title" => "Others"]]];
        } else {
            $attributes[] = ["name" => "organizationType", "description" => "Application Purpose (Organization only)", "type" => "select", "option" => [["value" => "OTA000003", "title" => "Company"], ["value" => "OTA000005", "title" => "Business"], ["value" => "OTA000025", "title" => "Non Profit Organization"], ["value" => "OTA000006", "title" => "Education"], ["value" => "OTA000012", "title" => "Legal Professional"], ["value" => "OTA000032", "title" => "Others"]]];
        }
        if(substr($this->options["tld"], -2) == "hk" || substr($this->options["tld"], -2) == "my") {
            $attributes[] = ["name" => "dateOfBirth", "description" => "Date of Birth (YYYY-MM-DD)", "type" => "input"];
        }
        if(!empty($attributes)) {
            foreach ($attributes as &$attr) {
                if(isset($this->options["ext"][$attr["name"]])) {
                    $attr["default"] = $this->options["ext"][$attr["name"]];
                }
            }
            return [trim($this->options["tld"], ". ") => $attributes];
        } else {
            return [];
        }
    }
    public function getRegisterNameServers()
    {
        $this->_prepare_name();
        $hosts = [];
        $api = $this->restApiV2();
        $params = ["filters" => [["field" => "hostname", "operator" => "LIKE", "value" => $this->name], ["field" => "status", "operator" => "EQUAL", "value" => "active"]], "pagination" => ["page" => 1, "pageSize" => 1]];
        $hostsListResponse = $api->post("domain/v2/host/list", $params);
        if($hostsListResponse["code"] != "1000") {
            return $hosts;
        }
        $params["pagination"]["pageSize"] = $hostsListResponse["data"]["totalItems"];
        $hostsListResponse = $api->post("domain/v2/host/list", $params);
        if($hostsListResponse["code"] != "1000") {
            return $hosts;
        }
        foreach ($hostsListResponse["data"]["items"] as $host) {
            $host = $api->get("domain/v2/host/info?host=" . $host["hostname"]);
            if($host["code"] != "1000") {
            } else {
                $hosts[$host["data"]["nameserver"]] = $host["data"]["ip"];
            }
        }
        return $hosts;
    }
    public function getNameServers()
    {
        $info = $this->getDomainDetails();
        if(!$info) {
            return false;
        }
        return $info["nameservers"];
    }
    private function getDomainDetails($key = NULL)
    {
        $this->_prepare_name();
        $response = $this->restApiV2()->get("domain/v2/info?domainName=" . $this->name);
        if($response["code"] == "1000") {
            if($key && array_key_exists($key, $response["data"])) {
                return $response["data"][$key];
            }
            return $response["data"];
        }
        Engine::addError("Unable_get_domain_info");
        return false;
    }
    public function updateNameServers()
    {
        $this->_prepare_name();
        $nameservers = $this->prepareNameserversData();
        $updateNameServersResponse = $this->restApiV2()->put("domain/v2/dns?domainName=" . $this->name, ["nameservers" => $nameservers]);
        if($updateNameServersResponse["code"] != "1000") {
            return false;
        }
        return true;
    }
    public function registerNameServer()
    {
        $this->_prepare_name();
        $name = rtrim($this->options["NameServer"], "." . $this->name) . "." . $this->name;
        $params = ["host" => $name, "extList" => [$this->options["tld"]]];
        $addresses = explode(",", $this->options["NameServerIP"]);
        foreach ($addresses as $address) {
            if(empty($address)) {
            } else {
                $params["ipList"][] = $address;
            }
        }
        $registerNameServerResponse = $this->restApiV2()->post("domain/v2/host/create/extension", $params);
        if($registerNameServerResponse["code"] != "3000") {
            $this->logAction(["action" => "Register Name Server", "result" => false, "change" => false, "error" => $registerNameServerResponse["error"]["message"]]);
            $this->addError($registerNameServerResponse["error"]["message"]);
            return false;
        }
        if(!empty($registerNameServerResponse["data"]["fail"])) {
            foreach ($registerNameServerResponse["data"]["fail"] as $fail) {
                $this->logAction(["action" => "Register Name Server", "result" => false, "change" => $name . " - " . $this->options["NameServerIP"], "error" => $fail["errors"]["message"]]);
            }
        }
        if(!empty($registerNameServerResponse["data"]["success"])) {
            $this->logAction(["action" => "Register Name Server", "result" => true, "change" => $name . " - " . $this->options["NameServerIP"], "error" => false]);
        }
        $this->addInfo($registerNameServerResponse["message"]);
        return true;
    }
    public function modifyNameServer()
    {
        $this->_prepare_name();
        $name = rtrim($this->options["NameServer"], "." . $this->name) . "." . $this->name;
        $getNameserverDetailsResponse = $this->restApiV2()->get("domain/v2/host/info?host=" . $name);
        if($getNameserverDetailsResponse["code"] != "1000") {
            return false;
        }
        $existingIps = $getNameserverDetailsResponse["data"]["ip"];
        $oldIp = explode(",", str_replace(" ", "", $this->options["NameServerOldIP"]));
        $newIp = explode(",", str_replace(" ", "", $this->options["NameServerNewIP"]));
        $existingIps = array_diff($existingIps, $oldIp);
        $existingIps = array_merge($existingIps, $newIp);
        $params = ["host" => $name, "ipList" => $existingIps];
        $modifyNameserverRequest = $this->restApiV2()->post("domain/v2/host/modify", $params);
        if($modifyNameserverRequest["code"] != "1000") {
            $this->logAction(["action" => "Modify NameServer", "result" => false, "change" => false, "error" => $modifyNameserverRequest["error"]["message"]]);
            $this->addError($modifyNameserverRequest["error"]["message"]);
            return false;
        }
        if(!empty($modifyNameserverRequest["data"]["success"])) {
            $this->logAction(["action" => "Modify NameServer", "result" => true, "change" => $name, "error" => false]);
        }
        $this->addInfo($modifyNameserverRequest["message"]);
        return true;
    }
    public function deleteNameServer()
    {
        $this->_prepare_name();
        $name = rtrim($this->options["NameServer"], "." . $this->name) . "." . $this->name;
        $getNameserverDetailsResponse = $this->restApiV2()->get("domain/v2/host/info?host=" . $name);
        if($getNameserverDetailsResponse["code"] != "1000") {
            return false;
        }
        $existingIps = $getNameserverDetailsResponse["data"]["ip"];
        $ipToDelete = $this->options["NameServerDeleteIP"];
        $index = array_search($ipToDelete, $existingIps);
        if($index !== false) {
            unset($existingIps[$index]);
        }
        if(empty($existingIps)) {
            $response = $this->restApiV2()->delete("domain/v2/host/extension?host=" . $name . "&ext=" . $this->options["tld"]);
        } else {
            $params = ["host" => $name, "ipList" => array_values($existingIps)];
            $response = $this->restApiV2()->post("domain/v2/host/modify", $params);
        }
        if($response["code"] != "1000") {
            $this->logAction(["action" => "Modify NameServer", "result" => false, "change" => false, "error" => $response["error"]["message"]]);
            $this->addError($response["error"]["message"]);
            return false;
        }
        if(!empty($response["data"]["success"])) {
            $this->logAction(["action" => "Modify NameServer", "result" => true, "change" => $name, "error" => false]);
        }
        $this->addInfo($response["message"]);
        return true;
    }
    public function Delete()
    {
        $domainContacts = $this->getDomainDetails("contactId");
        if(!$domainContacts) {
            return false;
        }
        $contacts = array_unique(array_values($domainContacts));
        if(!$this->name) {
            return false;
        }
        $response = $this->restApiV2()->delete("domain/v2/delete?domainName=" . $this->name);
        if($response["code"] == "1000") {
            foreach ($contacts as $contact) {
                $this->restApiV2()->delete("domain/v2/contact?contactId=" . $contact);
            }
            return true;
        } else {
            $errors = $response["error"];
            if(!$errors) {
                return false;
            }
            foreach ($errors as $error) {
                $this->addError($error["message"]);
            }
            return false;
        }
    }
    public function getContactInfo()
    {
        $domainContacts = $this->getDomainDetails("contactId");
        if(!$domainContacts) {
            return false;
        }
        $contactResponse = $this->restApiV2()->get("domain/v2/contact/query?contactId=" . $domainContacts["registrant"]);
        if(!$contactResponse) {
            return false;
        }
        $contactDetails = $contactResponse["data"]["details"];
        return ["firstname" => $contactDetails["firstName"], "lastname" => $contactDetails["lastName"], "companyname" => $contactDetails["company"], "email" => $contactDetails["email"], "address1" => $contactDetails["address1"], "address2" => $contactDetails["address2"], "city" => $contactDetails["city"], "state" => $contactDetails["state"], "postcode" => $contactDetails["zip"], "country" => $contactDetails["countryCode"], "phonenumber" => $contactDetails["phoneNumber"]];
    }
    public function ContactInfo()
    {
        $domainContacts = $this->getDomainDetails("contactId");
        if(!$domainContacts) {
            return false;
        }
        $contactResponse = $this->restApiV2()->get("domain/v2/contact/query?contactId=" . $domainContacts["registrant"]);
        if(!$contactResponse) {
            return false;
        }
        $contactDetails = $contactResponse["data"]["details"];
        return ["firstname" => $contactDetails["firstName"], "lastname" => $contactDetails["lastName"], "companyname" => $contactDetails["company"], "email" => $contactDetails["email"], "address1" => $contactDetails["address1"], "address2" => $contactDetails["address2"], "city" => $contactDetails["city"], "state" => $contactDetails["state"], "postcode" => $contactDetails["zip"], "country" => $contactDetails["countryCode"], "phonenumber" => $contactDetails["phoneNumber"]];
    }
    public function updateContactInfo()
    {
        $domainContacts = $this->getDomainDetails("contactId");
        if(!$domainContacts) {
            return false;
        }
        $contacts = [];
        foreach ($domainContacts as $key => $value) {
            if(!in_array($value, $contacts)) {
                $contacts[$key] = $value;
            }
        }
        $return = false;
        foreach ($contacts as $type => $contactId) {
            $newContact["details"] = $this->prepareContactData($type);
            $newContact["contactId"] = $contactId;
            $return = $this->restApiV2()->post("domain/v2/contact/modify", $newContact);
        }
        if($return) {
            $this->addInfo("Contact Info has been updated. Whois information will be updated within a minutes");
            return true;
        }
        return false;
    }
    public function synchInfo()
    {
        $domainDetails = $this->getDomainDetails();
        if(!$domainDetails) {
            return [];
        }
        $return = [];
        $return["status"] = DOMAIN_STATE_ACTIVE;
        $return["expires"] = $domainDetails["dtexpire"];
        if(strtotime($return["expires"]) < time()) {
            $return["status"] = DOMAIN_STATE_EXPIRED;
        }
        $return["ns"] = $domainDetails["nameservers"];
        $return["idprotection"] = $domainDetails["whoisPrivacy"];
        $return["autorenew"] = $domainDetails["autoRenew"];
        return $return;
    }
    public function updateIDProtection()
    {
        $this->_prepare_name();
        $domainDetails = $this->getDomainDetails();
        if(!$domainDetails) {
            return false;
        }
        $newProtection = !$domainDetails["whoisPrivacy"];
        $response = $this->restApiV2()->put("domain/v2/whois-privacy/toggle", ["domainName" => $this->name, "active" => $newProtection]);
        if($response["code"] != "1000") {
            $this->logAction(["action" => "Update ID Protection", "result" => false, "change" => false, "error" => $response["error"]["message"]]);
            $this->addError($response["error"]["message"]);
            return false;
        }
        if($domainDetails["whoisPrivacy"] === false) {
            $change = ["from" => "Disabled", "to" => "Enabled"];
        } else {
            $change = ["from" => "Enabled", "to" => "Disabled"];
        }
        $this->logAction(["action" => "Update ID Protection", "result" => true, "change" => $change, "error" => false]);
        $this->addInfo($response["message"]);
        return true;
    }
    public function getDomainPrices()
    {
        $prices = [];
        try {
            $api = $this->restApiV2();
            $tldsResponse = $api->get("domain/v2/exts");
            if($tldsResponse["code"] != "1000") {
                return $prices;
            }
            $tlsdString = implode(",", $tldsResponse["data"]);
            $priceParams = ["filters" => [["field" => "productKey", "value" => $tlsdString], ["field" => "transtype", "value" => "register,renewal,transfer,restore"]], "pagination" => ["page" => 1, "pageSize" => 1]];
            $tldPricesResponse = $api->post("domain/v2/exts/pricing", $priceParams);
            if($tldPricesResponse["code"] != "1000") {
                return $prices;
            }
            $priceParams["pagination"]["pageSize"] = $tldPricesResponse["data"]["totalItems"];
            $tldPricesResponse = $api->post("domain/v2/exts/pricing", $priceParams);
            if($tldPricesResponse["code"] != "1000") {
                return $prices;
            }
            $priceActs = ["register" => "register", "renewal" => "renew", "transfer" => "transfer", "restore" => "redemption"];
            foreach ($tldPricesResponse["data"]["items"] as $tldPrice) {
                foreach ($tldPrice["productPricing"]["price"] as $priceType => $priceTypeDetails) {
                    foreach ($priceTypeDetails["ascii"] as $year => $price) {
                        $prices["USD"]["." . $tldPrice["productKey"]][$year]["period"] = $year;
                        $prices["USD"]["." . $tldPrice["productKey"]][$year][$priceActs[$priceType]] = $price;
                    }
                }
                foreach ($tldPrice["localPrice"]["price"] as $priceType => $priceTypeDetails) {
                    foreach ($priceTypeDetails["ascii"] as $year => $price) {
                        $prices["USD"]["." . $tldPrice["productKey"]][$year]["period"] = $year;
                        $prices["USD"]["." . $tldPrice["productKey"]][$year][$priceActs[$priceType]] = $price;
                    }
                }
            }
        } catch (Exception $ex) {
            $this->addError($ex->getMessage());
            $this->addError("Failed to get TLD list");
        }
        return $prices;
    }
    public function hideContacts()
    {
        if($this->configuration["hide_form"]["value"] == "1") {
            return true;
        }
        return false;
    }
    public function hideNameServers()
    {
        return true;
    }
    public function upgrade($old)
    {
        if(version_compare($old, $this->version, "<")) {
            LangEdit::addTranslation("Unable_get_domain_info", "Unable to get domain info", "global", "admin");
            LangEdit::addTranslation("Unable_get_domain_info", "Unable to get domain info", "global", "user");
        }
    }
    private function createRegistrantAccount()
    {
        $userName = $this->getDomainName() . "_" . $this->getDomainId();
        $allRegistrantsResponse = $this->restApiV2()->get("domain/v2/registrant/list");
        if($allRegistrantsResponse["code"] != "1000") {
            return false;
        }
        foreach ($allRegistrantsResponse["data"] as $account) {
            if($account["username"] === $userName) {
                return $account["id"];
            }
        }
        $registrantResponse = $this->restApiV2()->post("domain/v2/registrant/create?username=" . $userName, []);
        if($registrantResponse["code"] != "1000") {
            return false;
        }
        return $registrantResponse["data"]["registrantUserId"];
    }
    private function submitRegistrarTransfer()
    {
        $params = $this->prepareMainParametersForRegisterOrTransfer(true);
        if(isset($this->options["ext"][$this->proxy_field]) && in_array($this->options["tld"], $this->proxy_tlds)) {
            $params["subscribeProxy"] = true;
        }
        if(!array_key_exists("subscribeProxy", $params)) {
            $params["subscribeProxy"] = false;
        }
        $params["authInfo"] = $this->options["epp_code"];
        if(!$params) {
            return false;
        }
        $transferResponse = $this->restApiV2()->post("domain/v2/transfer-in", $params);
        if($transferResponse["code"] != "1000") {
            return false;
        }
        return $transferResponse;
    }
    private function submitResellerTransfer()
    {
        $registrantAccount = $this->createRegistrantAccount();
        if(!$registrantAccount) {
            return false;
        }
        $params = ["domainName" => $this->name, "registrantUserId" => $registrantAccount];
        if(!$params) {
            return false;
        }
        $transferResponse = $this->restApiV2()->post("domain/v2/reseller-transfer", $params);
        if($transferResponse["code"] != "1000") {
            return false;
        }
        return $transferResponse;
    }
}
class WebNicRestApiV2
{
    use Components\Traits\LoggerTrait;
    public $http;
    public $endpoint;
    public $access_token;
    public $expire;
    public function __construct($endpoint)
    {
        $this->endpoint = $endpoint . "/";
        $this->init();
    }
    protected function init()
    {
        $this->http = new GuzzleHttp\Client();
    }
    public function __wakeup()
    {
        $this->init();
    }
    public function login($user, $password)
    {
        $response = $this->http->post($this->endpoint . "reseller/v2/api-user/token", ["headers" => ["Content-Type" => "application/json", "Accept" => "*/*"], "body" => json_encode(["username" => $user, "password" => $password])]);
        $access = json_decode($response->getBody(), true);
        if($access["code"] == "1000" && $access["data"]["access_token"]) {
            $this->access_token = $access["data"]["access_token"];
            $this->expire = time() + $access["data"]["expires_in"];
            return true;
        }
        throw new ErrorException(implode(": ", [$access["error"], $access["error_description"]]));
    }
    public function post($url, $params)
    {
        return $this->request("POST", $url, $params);
    }
    public function request($type, $url, $params = [])
    {
        try {
            $method = strtolower($type);
            $requestParams = ["headers" => ["Content-Type" => "application/json", "Authorization" => "Bearer " . $this->access_token]];
            if(!empty($params)) {
                $requestParams["body"] = json_encode($params);
            }
            $re = $this->http->{$method}($this->endpoint . $url, $requestParams);
            $response = json_decode($re->getBody(), true);
            HBDebug::debug("HostBill Webnic API V2 request", ["url" => $url, "params" => $params, "response" => $response]);
            $logData = ["url" => $url, "params" => $params, "response" => $re, "response_body" => $re->getBody(), "result" => $response];
            if($response["code"] != "1000") {
                $this->logger()->error("Webnic API V2 request error", $logData);
            } else {
                $this->logger()->debug("Webnic API V2 request debug", $logData);
            }
            return $response;
        } catch (GuzzleHttp\Exception\ClientException $exception) {
        } catch (Exception $exception) {
        }
        $response = $exception->getResponse();
        $decodedResponse = $response ? json_decode($response->getBody(), true) : NULL;
        $this->logger()->error("Webnic API V2 request caught exception", ["message" => $exception->getMessage(), "code" => $exception->getCode(), "response" => $decodedResponse, "request" => ["method" => $method, "url" => $this->endpoint . $url, "params" => $params]]);
        HBDebug::debug("HostBill Webnic API V2 request Exception", ["message" => $exception->getMessage(), "full_message" => (string) $exception, "code" => $exception->getCode(), "response" => $decodedResponse, "request" => ["method" => $method, "url" => $this->endpoint . $url, "params" => $params]]);
        return false;
    }
    public function delete($url)
    {
        return $this->request("DELETE", $url);
    }
    public function get($url)
    {
        return $this->request("GET", $url);
    }
    public function put($url, $params)
    {
        return $this->request("PUT", $url, $params);
    }
}

?>
