<?php

    namespace Ataccama\Eye\Client;

    use Ataccama\Common\Env\Entry;
    use Ataccama\Common\Env\IEntry;
    use Ataccama\Common\Utils\Cache\DataStorage;
    use Ataccama\Eye\Client\Env\Activities\Activity;
    use Ataccama\Eye\Client\Env\Activities\ActivityDefinition;
    use Ataccama\Eye\Client\Env\Activities\ActivityList;
    use Ataccama\Eye\Client\Env\Activities\Filter;
    use Ataccama\Eye\Client\Env\Activities\MetadataList;
    use Ataccama\Eye\Client\Env\CacheKeys\ActivityListKey;
    use Ataccama\Eye\Client\Env\CacheKeys\SessionKey;
    use Ataccama\Eye\Client\Env\CacheKeys\UserFilterKey;
    use Ataccama\Eye\Client\Env\CacheKeys\UserKey;
    use Ataccama\Eye\Client\Env\Sessions\Session;
    use Ataccama\Eye\Client\Env\Sessions\SessionDefinition;
    use Ataccama\Eye\Client\Env\Tags\Tag;
    use Ataccama\Eye\Client\Env\Tags\TagList;
    use Ataccama\Eye\Client\Env\Users\User;
    use Ataccama\Eye\Client\Env\Users\UserDefinition;
    use Ataccama\Eye\Client\Exceptions\AtaccamaEyeApiError;
    use Ataccama\Eye\Client\Exceptions\Unauthorized;
    use Ataccama\Eye\Client\Exceptions\UnknownError;
    use Ataccama\Eye\Client\Mappers\ActivityMapper;
    use Ataccama\Eye\Client\Mappers\ProfileMapper;
    use Curl\Curl;
    use Env\Tags\TagListKey;
    use Nette\Utils\DateTime;


    /**
     * Class Client
     * @package Ataccama\Eye\Client
     */
    class Client
    {
        /** @var string */
        private $host;

        /** @var string */
        private $bearer;

        /** @var int */
        private $version;

        /** @var DataStorage */
        private $cache;

        /** @var string */
        private $cacheExpiration;

        /**
         * Client constructor.
         * @param string $host
         * @param string $bearer
         * @param int    $version
         */
        public function __construct(string $host, string $bearer, int $version = 2)
        {
            $this->host = $host;
            $this->bearer = $bearer;
            $this->version = $version;
        }

        /**
         * @param DataStorage $dataStorage
         * @param string      $cacheExpiration
         */
        public function setCache(DataStorage $dataStorage, string $cacheExpiration = "+1 day"): void
        {
            $this->cache = $dataStorage;
            $this->cacheExpiration = $cacheExpiration;
        }

        /**
         * @return string
         */
        private function getBaseUri(): string
        {
            return $this->host . "/api/v" . "$this->version";
        }

        /**
         * @param SessionDefinition $sessionDefinition
         * @return Session
         * @throws AtaccamaEyeApiError
         * @throws UnknownError
         * @throws \ErrorException
         * @throws Unauthorized
         */
        public function createSession(SessionDefinition $sessionDefinition): Session
        {
            // data
            $data = [
                "ipAddress" => $sessionDefinition->ipAddress
            ];
            if (!empty($sessionDefinition->user)) {
                $data['userId'] = $sessionDefinition->user->id;
            }

            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->setHeader("Content-Type", "application/json");
            $curl->post($this->getBaseUri() . "/sessions", $data);

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok
                    return new Session($curl->response->id, DateTime::from($curl->response->dtCreated),
                        DateTime::from($curl->response->dtExpired), $curl->response->ipAddress,
                        $sessionDefinition->user);
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("A new session creation failed. Response: " . json_encode($curl->response));
        }

        /**
         * @param IEntry $session
         * @return Session
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         * @throws \Throwable
         */
        public function getSession(IEntry $session): Session
        {
            if (isset($this->cache)) {
                $_session = $this->cache->get(new SessionKey($session));
                if ($_session !== null) {
                    return $_session;
                }
            }

            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->get($this->getBaseUri() . "/session?id=" . $session->id);

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok
                    $session = new Session($curl->response->id, DateTime::from($curl->response->dtCreated),
                        DateTime::from($curl->response->dtExpired), $curl->response->ipAddress,
                        !empty($curl->response->userId) ? new Entry($curl->response->userId) : null);

                    foreach ($curl->response->activities as $activity) {
                        $session->activities->add((new ActivityMapper($activity))->getObject());
                    }

                    if (isset($this->cache)) {
                        $this->cache->add(new SessionKey($session), $session, $this->cacheExpiration);
                    }

                    return $session;
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("Getting a session failed. Response: " . json_encode($curl->response));
        }

        /**
         * @param ActivityDefinition $activityDefinition
         * @param MetadataList|null  $metadata
         * @return Activity
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         * @throws \Throwable
         */
        public function createActivity(ActivityDefinition $activityDefinition, MetadataList $metadata = null): Activity
        {
            // data
            $data = [
                "sessionId" => $activityDefinition->session->id,
                "ipAddress" => $activityDefinition->ipAddress,
                "typeId"    => $activityDefinition->type->id
            ];
            if (isset($metadata)) {
                foreach ($metadata as $pair) {
                    $data['metadata'][$pair->key] = $pair->value;
                }
            }

            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->setHeader("Content-Type", "application/json");
            $curl->post($this->getBaseUri() . "/activities", $data);

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok

                    if (isset($this->cache)) {
                        $this->cache->notifyChange(new SessionKey($activityDefinition->session));
                    }

                    return (new ActivityMapper($curl->response))->getObject();
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("A creation a new activity failed. Response: " . json_encode($curl->response));
        }

        public function listActivities(Filter $filter): ActivityList
        {
            if (isset($this->cache)) {
                $activities = $this->cache->get(new ActivityListKey($filter));
                if ($activities !== null) {
                    return $activities;
                }
            }

            // data
            $data = [
                "dtFrom"  => $filter->dtFrom->format("Y-m-d"),
                "dtTo"    => $filter->dtTo->format("Y-m-d"),
                "typeIds" => $filter->typeIds
            ];
            if (!empty($filter->ipAddress)) {
                $data["ipAddress"] = $filter->ipAddress;
            }
            if (!empty($filter->email)) {
                $data["email"] = $filter->email->definition;
            }
            if (!empty($filter->continents)) {
                $data["continentIso2s"] = $filter->continents;
            }
            if (!empty($filter->metadataSearch)) {
                $data["metadataSearchTerm"] = $filter->metadataSearch->value;
                if (!empty($filter->metadataSearch->key)) {
                    $data["metadataKey"] = $filter->metadataSearch->key;
                }
            }

            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->get($this->getBaseUri() . "/activities", $data);

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok
                    $activities = new ActivityList();
                    foreach ($curl->response as $activity) {
                        $activities->add((new ActivityMapper($activity))->getObject());
                    }

                    if (isset($this->cache)) {
                        $this->cache->add(new ActivityListKey($filter), $activities, $this->cacheExpiration);
                    }

                    return $activities;
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("Getting activities failed. Response: " . json_encode($curl->response));
        }

        /**
         * @param UserDefinition $userDefinition
         * @return User
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         */
        public function createUser(UserDefinition $userDefinition): User
        {
            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->setHeader("Content-Type", "application/json");
            $curl->post($this->getBaseUri() . "/users", $userDefinition->toArray());

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok
                    return (new ProfileMapper($curl->response))->getObject();
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("A new user creation failed. Response: " . json_encode($curl->response));
        }

        /**
         * @param Env\Users\Filter $filter
         * @return User
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         * @throws \Throwable
         */
        public function getUser(\Ataccama\Eye\Client\Env\Users\Filter $filter): User
        {
            if (isset($this->cache)) {
                if (empty($filter->id)) {
                    $userKey = $this->cache->get(new UserFilterKey($filter));
                    if ($userKey !== null) {
                        $user = $this->cache->get($userKey);
                        if ($user !== null) {
                            return $user;
                        }
                    }
                } else {
                    $user = $this->cache->get(new UserKey(new Entry($filter->id)));
                    if ($user !== null) {
                        return $user;
                    }
                }
            }

            $query = "";
            if (isset($filter->id)) {
                $query = "id=$filter->id";
            } elseif (isset($filter->session)) {
                $query = "sessionId=" . $filter->session->id;
            } elseif (isset($filter->keycloakId)) {
                $query = "keycloakId=$filter->keycloakId";
            } elseif (isset($filter->email)) {
                $query = "email=$filter->email";
            }

            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->get($this->getBaseUri() . "/user?$query");

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok
                    $user = (new ProfileMapper($curl->response))->getObject();
                    if (isset($this->cache)) {
                        $this->cache->add(new UserKey($user), $user, $this->cacheExpiration);
                        $this->cache->add(UserKey::emailKey($user), new UserKey($user), $this->cacheExpiration);
                        if (!empty($user->keycloakId)) {
                            $this->cache->add(UserKey::keycloakKey($user), new UserKey($user), $this->cacheExpiration);
                        }
                        foreach ($user->sessions as $session) {
                            $this->cache->add(UserKey::sessionKey($session), new UserKey($user),
                                $this->cacheExpiration);
                            $this->cache->createDependency(new SessionKey($session), new UserKey($user));
                        }
                    }

                    return $user;
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("Getting an user failed. Response: " . json_encode($curl->response));
        }

        /**
         * @param User $user
         * @return User
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         * @throws \Throwable
         */
        public function updateUser(User $user): User
        {
            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->setHeader("Content-Type", "application/json");
            $curl->patch($this->getBaseUri() . "/user", $user->toArray());

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok

                    if (isset($this->cache)) {
                        $this->cache->notifyChange(new UserKey($user));
                    }

                    return (new ProfileMapper($curl->response))->getObject();
                case 403:
                    if (isset($curl->response->message)) {
                        throw new Unauthorized($curl->response->message);
                    }
                default:
                    if (isset($curl->response->message)) {
                        throw new AtaccamaEyeApiError($curl->response->message);
                    }
            }
            throw new UnknownError("Updating an user failed. Response: " . json_encode($curl->response));
        }

        /**
         * @param IEntry $session
         * @param IEntry $user
         * @return bool
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         * @throws \Throwable
         */
        public function identifySession(IEntry $session, IEntry $user): bool
        {
            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->setHeader("Content-Type", "application/json");
            $curl->post($this->getBaseUri() . "/session/identify", [
                "sessionId" => $session->id,
                "userId"    => $user->id
            ]);

            switch ($curl->getHttpStatusCode()) {
                case 204:
                    // ok

                    if (isset($this->cache)) {
                        $this->cache->notifyChange(new SessionKey($session));
                        $this->cache->notifyChange(new UserKey($user));
                        $this->cache->createDependency(new SessionKey($session), new UserKey($user));
                    }

                    return true;
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("Identification of a session failed. Response: " . json_encode($curl->response));
        }

        /**
         * @return TagList
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         * @throws \Throwable
         */
        public function listTags(): TagList
        {
            if (isset($this->cache)) {
                $activities = $this->cache->get(new TagListKey());
                if ($activities !== null) {
                    return $activities;
                }
            }

            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->get($this->getBaseUri() . "/tags");

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok
                    $tags = new TagList();
                    foreach ($curl->response as $tag) {
                        $tags->add(new Tag($tag->id, $tag->name));
                    }

                    if (isset($this->cache)) {
                        $this->cache->add(new TagListKey(), $tags, $this->cacheExpiration);
                    }

                    return $tags;
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("Getting tags failed. Response: " . json_encode($curl->response));
        }

        /**
         * @param Env\Users\Filter $filter
         * @return int
         * @throws AtaccamaEyeApiError
         * @throws Unauthorized
         * @throws UnknownError
         * @throws \ErrorException
         */
        public function getUserId(\Ataccama\Eye\Client\Env\Users\Filter $filter): int
        {
            $query = "";
            if (isset($filter->session)) {
                $query = "sessionId=" . $filter->session->id;
            } elseif (isset($filter->keycloakId)) {
                $query = "keycloakId=$filter->keycloakId";
            } elseif (isset($filter->email)) {
                $query = "email=$filter->email";
            }

            // API call
            $curl = new Curl();
            $curl->setHeader("Authorization", "Bearer $this->bearer");
            $curl->get($this->getBaseUri() . "/user/id?$query");

            switch ($curl->getHttpStatusCode()) {
                case 200:
                    // ok
                    return $curl->response->id;
                case 403:
                    if (isset($curl->response->error)) {
                        throw new Unauthorized($curl->response->error);
                    }
                default:
                    if (isset($curl->response->error)) {
                        throw new AtaccamaEyeApiError($curl->response->error);
                    }
            }
            throw new UnknownError("Getting an user ID failed. Response: " . json_encode($curl->response));
        }
    }