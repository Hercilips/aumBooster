<?php
require_once 'goutte.phar';
require_once 'yaml/sfYaml.php';

use Goutte\Client;

class aumBooster
{
    private $client;
    private $crawler;
    private $usersLookupCounter = 0;
    private $params = array();
    private $hitCountersTab = array();
    private $sessionCookie = null;
    private $userId = null;
    private $contactIdsTab = array();

    public function __construct(array $params)
    {
        $this->params = $params;

	date_default_timezone_set($this->params['default_timezone']);

        $this->client = new Client(array('HTTP_USER_AGENT' => $this->params['user_agents'][rand(0, count($this->params['user_agents']) - 1)]));

        $homepageGet = false;

        while(false === $homepageGet)
        {
            try
            {
                echo 'Homepage GET' . PHP_EOL;

                $this->crawler = $this->client->request('GET', 'http://www.adopteunmec.com/');

                $homepageGet = true;
            }
            catch(Exception $e)
            {
                echo 'Timeout Homepage GET' . PHP_EOL;
                sleep($this->params['retry_timeout']);
            }
        }

        echo 'Homepage Form' . PHP_EOL;

        $form = $this->crawler->filter('#login')->form();

        $homepageFormSubmit = false;

        while(false === $homepageFormSubmit)
        {
            try
            {
                echo 'Homepage Form Submit' . PHP_EOL;

                $this->crawler = $this->client->submit(
                    $form,
                    array(
                        'username' => $this->params['username'],
                        'password' => $this->params['password'],
                        'remember' => $this->params['remember'],
                    )
                );

                $homepageFormSubmit = true;
            }
            catch(Exception $e)
            {
                echo 'Timeout Sign In Form Submit' . PHP_EOL;
                sleep($this->params['retry_timeout']);
            }
        }

        $this->sessionCookie = $this->client->getCookieJar()->get('AUMSESSID21')->getValue();
        $this->userId = $this->client->getCookieJar()->get('aum_user')->getValue();
    }

    public function crawl()
    {
        while(true)
        {
            if(date('H') >= $this->params['is_online_crawl_start_hour'] && date('H') <= $this->params['is_online_crawl_stop_hour'])
            {
                $this->crawlRange($this->params['form']['age']['min'], $this->params['form']['age']['max'], $this->params['form']['size']['min'], $this->params['form']['size']['max']);
            }
            else
            {
                $this->waitForCrawlHours();
                $this->crawlRange($this->params['form']['age']['min'], $this->params['form']['age']['max'], $this->params['form']['size']['min'], $this->params['form']['size']['max']);
            }
        }
    }

    private function waitForCrawlHours(){
        while(date('H') < $this->params['crawl_start'] || date('H') > $this->params['crawl_end'])
        {
            sleep(3600);
        }
    }

    private function getContactIds()
    {
        $chatLoaded = false;

        while(false === $chatLoaded)
        {
            try
            {
                $this->crawler = $this->client->request('GET', 'http://www.adopteunmec.com/messages/ajax_fetchGroups');

                $chatLoaded = true;
            }
            catch(Exception $e)
            {
                echo 'Timeout Loading AuM Chat' . PHP_EOL;
                sleep($this->params['retry_timeout']);
            }
        }

        $output = $this->client->getResponse()->getContent();
        $contacts =json_decode($output);
        $contactIdsTab = array();
        
        if(isset($contacts->groups)) {
            foreach ($contacts->groups as $contactGroup) {
                if(isset($contactGroup->contacts)) {
                    foreach ($contactGroup->contacts as $contact) {
                        $contactIdsTab[] = $contact->id;
                    }
                }
            }
        }

        return $contactIdsTab;
    }

    private function crawlRange($ageMin, $ageMax, $sizeMin, $sizeMax)
    {
        /**
         * Where is the search link
         */
        try
        {
            $link = $this->crawler->selectLink('Recherche')->link();
        }
        catch(InvalidArgumentException $e)
        {
            echo 'No "Recherche" Link with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;

            return false;
        }

        $this->contactIdsTab = array(); //$this->getContactIds();

        /**
         * Click on that search link
         */
        $rechercheClick = false;

        while(false === $rechercheClick)
        {
            try
            {
                echo '"Recherche" Link Click with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;

                $this->crawler = $this->client->click($link);

                $rechercheClick = true;
            }
            catch(Exception $e)
            {
                echo 'Timeout Recherche Click with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;
                sleep($this->params['retry_timeout']);
            }
        }

        /**
         * Lookup for the search form
         */
        echo 'Search Form with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;

        try
        {
            $form = $this->crawler->filter('#search-form')->form();
        }
        catch(InvalidArgumentException $e)
        {
            echo 'No Search Form with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;

            return false;
        }

        /**
         * Submit the search form with the current parameters
         */
        $searchFormSubmit = false;

        while(false === $searchFormSubmit)
        {
            try
            {
                echo 'Search Form Submit with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;

                $array = $form->getValues();
                // override form value here
                $array['pseudo'] = "";
                $array['age[min]'] = $ageMin;
                $array['age[max]'] = $ageMax;
                $array['by'] = $this->params['form']['by'];
                $array['country'] = $this->params['form']['country'];
                $array['region'] = $this->params['form']['region'];
                $array['subregion'] = array();
                $array['sex'] = $this->params['form']['sex'];
                $array['size[min]'] = $sizeMin;
                $array['size[max]'] = $sizeMax;

                // weirdo, but should work
                foreach ($this->params['form']['shape'] as $key => $shape) {
                    $array['shape[' . $key . ']'] = $shape;
                }
                foreach ($this->params['form']['shape'] as $key => $shape) {
                    $array['shape[' . (6 + $key) . ']'] = $shape;
                }

                $this->crawler = $this->client->submit($form, $array);

                $searchFormSubmit = true;
            }
            catch(Exception $e)
            {
                echo 'Timeout Form Submit with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;
                sleep($this->params['retry_timeout']);
            }
        }

        /**
         * Are there users in the search results ? And get the users in the first page
         */
        $page = 1;

        try
        {
            preg_match('/var members=({.*}),/', $this->client->getResponse()->getContent(), $matches);
            $res = json_decode($matches[1]);
            $users = $res->members;
        }
        catch(InvalidArgumentException $e)
        {
            echo 'No Users in search result with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;

            return false;
        }

        /**
         * Paginate on the search results
         */
        try
        {
            while(0 < count($users))
            {
                $this->hitCountersPurge();

                if(date('H') >= $this->params['is_online_crawl_start_hour'] && date('H') <= $this->params['is_online_crawl_stop_hour'])
                {
                    $onlineUsers = array_filter($users, function($user) {
                        return false !== ($user->isOnline == '1') ? true : false;
                    });

                    if(0 === count($onlineUsers))
                    {
                        echo 'No More Online Users in search result with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;

                        return false;
                    }
                }
                else
                {
                    $onlineUsers = $users;
                }

                $links = array();
                foreach ($onlineUsers as $user) {
                    array_push($links, $user->url);
                }

                foreach($links as $link)
                {
                    if(!in_array(substr($link, 35), $this->contactIdsTab))
                    {
                        if(
                            empty($this->hitCountersTab[$link]) ||
                            count($this->hitCountersTab[$link]) < $this->params['max_hits_by_period']
                        )
                        {
                            $userLookup = false;

                            while(false === $userLookup)
                            {
                                try
                                {
                                    $crawler = $this->client->request('GET', 'http://www.adopteunmec.com' . $link);

                                    $userLookup = true;
                                }
                                catch(Exception $e)
                                {
                                    echo 'Timeout User Lookup : ' . $link . PHP_EOL;
                                    sleep($this->params['retry_timeout']);
                                }
                            }

                            $this->hitCountersTab[$link][] = time();
                            $this->usersLookupCounter++;

                            echo str_pad($this->usersLookupCounter, 10, '0', STR_PAD_LEFT) . ' (' . count($this->hitCountersTab[$link]) . ') ' . $link . PHP_EOL;

                            sleep(rand($this->params['sleep_between_hits']['min'], $this->params['sleep_between_hits']['max']));
                        }
                    }
                }

                $page++;

                $pageClick = false;

                while(false === $pageClick)
                {
                    try
                    {
                        $this->crawler = $this->client->request('GET', 'http://www.adopteunmec.com/mySearch?page=' . $page);

                        $pageClick = true;
                    }
                    catch(Exception $e)
                    {
                        echo 'Timeout Page ' . $page . ' Click with age beetween ' . $ageMin . ' and ' . $ageMax . ' and size beetween ' . $sizeMin . ' and ' . $sizeMax . PHP_EOL;
                        sleep($this->params['retry_timeout']);
                    }
                }

                preg_match('/var members=({.*}),/', $this->client->getResponse()->getContent(), $matches);
                $res = json_decode($matches[1]);
                if (empty($res->members)) // in case we reach the max of pages, so break and do again a research
                    break;

                $users = $res->members;
            }
        }
        catch(InvalidArgumentException $e)
        {
            echo 'END | AGE : ' . $ageMin . ' / ' . $ageMax . ' | SIZE : ' . $sizeMin . ' / ' . $sizeMax . PHP_EOL . '----------------------' . PHP_EOL;
        }
    }

    private function hitCountersPurge()
    {
        foreach($this->hitCountersTab as $link => $hits)
        {
            foreach($hits as $key => $timestamp)
            {
                if($timestamp < time() - $this->params['hits_counters_ttl'])
                {
                    unset($this->hitCountersTab[$link][$key]);
                }
            }
        }
    }
}

/**
 * runtime
 */
$aumBooster = new aumBooster(sfYaml::load('aumBooster.yml'));
$aumBooster->crawl();
