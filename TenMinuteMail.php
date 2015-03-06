<?php

namespace TenMinuteMail;

class Service
{
    /** @var string key for unique cookie */
    private $uniqueId;

    /** @var string temporary files dir */
    private $tmpDir;

    /** @var resource CURL connection */
    private $connect;

    /** @var bool remove cookie file from tmpDir */
    private $destroyCookie;

    /** @var string uri for getting email and receiving mails */
    private $uri;

    /** @var string uri for prolongation mailbox usage */
    private $renewUri;

    /** @var Email[] array of emails */
    private $mails = array();

    /** @var string cookies */
    private $cookies;

    /** @var string email address */
    private $email;

    /** @var int time to end mailbox in minutes */
    private $remainingTime;


    public function __construct($uniqueId, $tmpDir, $destroyCookie=true)
    {
        $this->uniqueId = $uniqueId;
        $this->tmpDir = $tmpDir;
        $this->destroyCookie = $destroyCookie;
        $this->uri = 'http://10minutemail.com/10MinuteMail/index.html';
        $this->connect = curl_init();
        $this->loadCookie();

        $curlOpts = array(
            CURLOPT_URL => $this->uri,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIESESSION => true
        );
        if ($this->cookies) {
            $curlOpts[CURLOPT_COOKIESESSION] = false;
            $curlOpts[CURLOPT_COOKIE] = $this->cookies;
        }
        curl_setopt_array($this->connect, $curlOpts);
    }

    public function setProxy($proxy)
    {
        curl_setopt($this->connect, CURLOPT_PROXY, $proxy);
    }

    private function loadCookie()
    {
        $file = $this->getCookieFullPath();
        if (file_exists($file) && is_readable($file)) {
            $this->cookies = file_get_contents($file);
        }
    }

    private function getCookieFullPath()
    {
        return $this->getCookieDir() . '/' . $this->getCookieName();
    }

    private function getCookieDir()
    {
        return $this->tmpDir;
    }

    private function getCookieName()
    {
        return $this->uniqueId . '.cookie';
    }

    public function __destruct()
    {
        curl_close($this->connect);
        if ($this->destroyCookie && file_exists($this->getCookieFullPath())) {
            unlink($this->getCookieFullPath());
        }
    }

    public function getNewAddress()
    {
        if (!$this->cookies) {
            curl_setopt($this->connect, CURLOPT_COOKIE, '');
        }
        curl_setopt($this->connect, CURLOPT_URL, $this->uri);
        $response = curl_exec($this->connect);

        if (preg_match_all('/Set-Cookie:\s*(.*?);/Umi', $response, $matches) && array_key_exists(1, $matches)) {
            $this->cookies = implode('; ', $matches[1]);
            file_put_contents($this->getCookieFullPath(), $this->cookies);
        }
        else {
            file_put_contents($this->getCookieDir().'/abc.cookie', $this->cookies);
            throw new \Exception('Can\'t get cookies');
        }
        curl_setopt($this->connect, CURLOPT_COOKIE, $this->cookies);

        $matches = array();
        if (preg_match('/<input\s+id="addyForm:addressSelect"\s+type="text"\s+name="addyForm:addressSelect"\s+value="([^\"]*)"/mi', $response, $matches)
            && array_key_exists(1, $matches))
        {
            $this->email = $matches[1];
            $this->refreshRenewURL($response);
            $this->mails = array();
        }
        else {
            file_put_contents($this->getCookieDir().'/abc.cookie', $this->cookies);
            throw new \Exception('Can\'t parse email');
        }
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getRemainingTime()
    {
        return $this->remainingTime;
    }

    /**
     * @throws \Exception
     */
    public function renew()
    {
        curl_setopt($this->connect, CURLOPT_URL, $this->renewUri);
        $this->refreshRenewURL(curl_exec($this->connect));
    }

    /**
     * @return Email[]
     */
    public function getMails()
    {
        return $this->mails;
    }

    /**
     * @return Email[]
     * @throws \Exception
     */
    public function check()
    {
        curl_setopt($this->connect, CURLOPT_URL, $this->uri);
        $response = curl_exec($this->connect);
        $this->refreshRenewURL($response);

        if (preg_match('/<span.+?id="expirationTime"[^>]*>.*?Your.*?e\-mail.*?address.*?will.*?expire.*?in[^\d]*(\d+)[^\d]*?minutes.*?\..*?<\/span>/Umi', $response, $matches)
            && array_key_exists(1, $matches))
        {
            $this->remainingTime = (int) $matches[1];
        }
        else {
            file_put_contents($this->getCookieDir().'/abc.cookie', $this->cookies);
            throw new \Exception('Can\'t parse expiration time');
        }

        if (!preg_match('/<table.+?id="emailTable"[^>]*>[\n\r\s\t]*<thead[^>]*>(.*?)<\/thead>[\n\r\s\t]*<tbody[^>]*>(.*?)<\/tbody>[\n\r\s\t]*<\/table>/si', $response, $tableMatches)
            || !array_key_exists(2, $tableMatches))
        {
            file_put_contents($this->getCookieDir().'/abc.cookie', $this->cookies);
            throw new \Exception('Can\'t find mail table');
        }

        // remove 2 elements from beginning (its all and title row)
        if (count($tableMatches) > 2) {
            array_shift($tableMatches);
            array_shift($tableMatches);
            $tableMatches = implode('', $tableMatches);
        }
        else {
            $tableMatches = '';
        }
        $matchRes = preg_match_all(
            '/<tr[^>]*>.*?'
                .'[\n\r\s\t]*<td[^>]*>.*?<input.*?name="emailTable:\d+:j_id\d*"[^>]*>.*?<\/td>'  // Read
                .'[\n\r\s\t]*<td[^>]*>(.*?)<\/td>'                                               // From
                .'[\n\r\s\t]*<td[^>]*>.*?<a.*?href="(.*?)"[^>]*>(.*?)<\/a>.*?<\/td>'             // Subject
                .'[\n\r\s\t]*<td[^>]*>.*?<\/td>'                                                 // Preview
                .'[\n\r\s\t]*<td[^>]*>(.*?)<\/td>'                                               // Date
                .'[\n\r\s\t]*<\/tr>/si',
            $tableMatches,
            $matches,
            PREG_SET_ORDER
        );
        if ($matchRes) {
            foreach ($matches as $match) {
                if (count($match) !== 5) {
                    continue;
                }
                $mail = new Email();
                $mail->setSender(trim($match[1]));
                $mail->setUrl('http://10minutemail.com'.urldecode(htmlspecialchars_decode(trim($match[2]))));
                $mail->setSubject(trim($match[3]));
                $mail->setDate(strtotime(trim($match[4])));

                $this->mails[] = $this->parseEmail($mail);
            }
        }
    }

    private function refreshRenewURL($response)
    {
        if (preg_match('/Give me.*?<a.*?href="([^"]*)".*?id="j_id\d+".*?>\d+.*?more/mi', $response, $matches)
            && array_key_exists(1, $matches))
        {
            $matches[1] = htmlspecialchars_decode($matches[1]);
            $this->renewUri = 'http://10minutemail.com'.str_replace('index.html','index.html;'.$this->cookies,urldecode($matches[1]));
        }
        else {
            file_put_contents($this->getCookieDir().'/abc.cookie', $this->cookies);
            throw new \Exception('Can\'t get refresh url');
        }
    }

    private function parseEmail(Email $mail)
    {
        curl_setopt($this->connect, CURLOPT_URL, $mail->getUrl());
        $response = curl_exec($this->connect);
        if (preg_match('/<strong>(.*?)<\/strong>(.*?)<strong>(.*?)<\/strong>(.*?)<strong>(.*?)<\/strong>(.*?)<br\s*\/>(.*?)<div\s*style="clear:both"><\/div>(.*?)<div\s*id="j_id\d+"\s*style="font-size:\s*0px;">/si', $response, $aMatches)) {
            $mail->setSubject(trim($aMatches[6]));
            $mail->setMessage(trim($aMatches[8]));

            return $mail;
        }
        file_put_contents($this->getCookieDir().'/abc.cookie', $this->cookies);
        throw new \Exception('Can\'t get mail content');
    }
}