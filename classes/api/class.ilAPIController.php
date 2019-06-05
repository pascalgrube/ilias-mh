<?php

/**
 * simple REST API for the video player
 *
 * @author Leon Kiefer <leon.kiefer@tik.uni-stuttgart.de>
 */
class ilAPIController
{

    /**
     *
     * @var ilMatterhornPlugin
     */
    private $plugin;

    /**
     * the configuration for the matterhorn object
     *
     * @var ilMatterhornConfig
     */
    private $configObject;

    /**
     * Constructor
     *
     * @param mixed $uri
     *            the parsed REQUEST_URI
     * @param string $method
     *            the REQUEST_METHOD
     * @access public
     */
    public function __construct($uri, $method)
    {
        $this->params = array();
        $this->plugin = ilPlugin::getPluginObject(IL_COMP_SERVICE, 'Repository', 'robj', 'Matterhorn');

        if ($method == 'GET') {
            parse_str($uri["query"], $this->params);
        } elseif ($method == 'PUT') {
            parse_str(file_get_contents("php://input"), $this->params);
        }

        $this->plugin->includeClass("class.ilMatterhornConfig.php");
        $this->plugin->includeClass("class.ilObjMatterhornAccess.php");
        $this->configObject = new ilMatterhornConfig();
    }

    /**
     * Main function for handle Requests
     *
     * @param string $path
     *            the path of the request
     * @return boolean
     */
    public function handleRequest($path)
    {
        ilLoggerFactory::getLogger('xmh')->debug("Request for:" . $path);

        try {
            // check if it is a request for an episode
            if (0 == strcmp("/episode.json", $path)) {
                $epsiode = $this->getEpisodeFromParameter();
                ilObjMatterhornAccess::checkEpisodeAccess($epsiode);
                $this->sendEpisode($epsiode);
            } else if (0 == strcmp("/usertracking", $path)) {
                $epsiode = $this->getEpisodeFromParameter();
                if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                    switch ($this->params['type']) {
                        case "FOOTPRINT":
                            ilObjMatterhornAccess::checkEpisodeAccess($epsiode);
                            $this->putUserTracking($epsiode);
                            break;
                        case "VIEWS":
                            throw new Exception("not implemented yet", 404);
                            break;
                        default:
                            throw new Exception($this->plugin->txt("no_such_method"), 404);
                    }
                } else {
                    throw new Exception($this->plugin->txt("no_such_method"), 404);
                }
            } else if (0 == strcmp("/usertracking/stats.json", $path)) {
                $epsiode = $this->getEpisodeFromParameter();
                ilObjMatterhornAccess::checkEpisodeAccess($epsiode);
                $this->sendStats($epsiode);
            } else if (0 == strcmp("/usertracking/footprint.json", $path)) {
                $epsiode = $this->getEpisodeFromParameter();
                ilObjMatterhornAccess::checkEpisodeAccess($epsiode);
                $this->sendFootprint($epsiode);
            } else if (0 == strcmp("/usertracking/statistic.json", $path)) {
                $epsiode = $this->getEpisodeFromParameter();
                ilObjMatterhornAccess::checkEpisodeAccess($epsiode, "write");
                $this->sendStatistic($epsiode);
            } else if (0 == strcmp("/info/me.json", $path)) {
                $this->sendMe();
            } else if (0 == strcmp("/manager/list.json", $path)) {
                $this->sendList();
            } else {
                throw new Exception("Bad Request", 400);
            }
        } catch (Exception $e) {
            $this->sendError($e);
        }
    }

    /**
     * extract series_id and episode_id from the request param
     *
     * @return ilMatterhornEpisode
     */
    private function getEpisodeFromParameter()
    {
        $ids = explode('/', $this->params['id'], 2);
        $series_id = $ids[0];
        $episode_id = $ids[1];
        return $this->getEpisode($series_id, $episode_id);
    }

    /**
     * get the epsiode from the series_id and the episode_id
     *
     * @param string $series_id
     * @param string $episode_id
     * @return ilMatterhornEpisode
     */
    private function getEpisode(string $series_id, string $episode_id)
    {
        $this->plugin->includeClass("class.ilMatterhornEpisode.php");
        return new ilMatterhornEpisode($series_id, $episode_id);
    }

    /**
     * Send the requested eposide.json
     *
     * @param ilMatterhornEpisode $episode
     */
    private function sendEpisode(ilMatterhornEpisode $episodeObject)
    {
        $episodeData = $episodeObject->getEpisode();
        $publication = $episodeObject->getPublication();

        $attachments = array(
            "attachment" => array()
        );
        $previewrefs = [];
        foreach ($publication->attachments as $attachment) {
            $att = array(
                'id' => $attachment->id,
                'type' => $attachment->flavor,
                'ref' => $attachment->ref,
                'mimetype' => $attachment->mediatype,
                'url' => $attachment->url, // TODO sign
                'tags' => array(
                    'tag' => $attachment->tags
                )
            );
            if ($att['type'] == "presentation/segment+preview") {
                preg_match("/(.*)time=(.*)F(\d+)/", $att['ref'], $regmatches);
                $previewrefs[$regmatches[2]] = $att;
            }
            array_push($attachments['attachment'], $att);
        }
        // ilLoggerFactory::getLogger('xmh')->debug((string) $segmentxml->MediaTime->MediaDuration);
        // ilLoggerFactory::getLogger('xmh')->debug(print_r($previewrefs,true));

        $metadata = array(
            "catalog" => array()
        );
        $segmentsUrl = null;
        foreach ($publication->metadata as $catalog) {
            $cat = array(
                'id' => $catalog->id,
                'type' => $catalog->flavor,
                'mimetype' => $catalog->mediatype,
                'url' => $catalog->url, // TODO sign
                'tags' => array(
                    'tag' => $catalog->tags
                )
            );

            if (0 == strcmp($cat['type'], 'mpeg-7/segments')) {
                $segmentsUrl = $catalog->url;
            }
            if (0 == strcmp($cat['type'], 'mpeg-7/text')) {
                $segmentsUrl = $catalog->url;
            }
            array_push($metadata['catalog'], $cat);
        }

        $media = array(
            "track" => array()
        );
        foreach ($publication->media as $track) {
            $trk = array(
                'id' => $track->id,
                'type' => $track->flavor,
                'mimetype' => $track->mediatype,
                'url' => $track->url, // TODO sign
                'duration' => $track->duration, // TODO data type int/string?
                'tags' => array(
                    'tag' => $track->tags
                )
            );
            if ($track->has_video) {
                $trk['video'] = array();
                $trk['video']['id'] = "video-1"; // TODO
                $trk['video']['resolution'] = "100x100"; // TODO
            }
            if ($track->has_audio) {
                $trk['audio'] = array();
                $trk['audio']['id'] = "audio-1"; // TODO
            }
            array_push($media['track'], $trk);
        }

        if ($segmentsUrl) {
            $segments = $this->convertSegment($segmentsUrl, $previewrefs);
        }

        $episode = array(
            'search-results' => array(
                "total" => "1",
                "result" => array(
                    'id' => $episodeData->identifier,
                    'mediaType' => "AudioVisual",
                    "dcCreated" => $episodeData->start,
                    "dcExtent" => 0, // TODO
                    "dcTitle" => $episodeData->title,
                    "dcIsPartOf" => $episodeData->is_part_of,
                    // TODO "dcCreator" => $episodeData->,
                    "mediapackage" => array(
                        'attachments' => $attachments,
                        'metadata' => $metadata, // TODO reqired?
                        'media' => $media,
                        'duration' => "0", // TODO
                        'id' => $episodeObject->getEpisodeId()
                    ),
                    "segments" => $segments
                )
            )
        );

        $this->sendJSON($episode);
    }

    /**
     *
     * @param string $url
     * @param array $previewrefs
     * @return array[]
     */
    private function convertSegment(string $url, array $previewrefs)
    {
        $segmentsxml = new SimpleXMLElement($url, null, true);

        $segments = array(
            "segment" => array()
        );
        $currentidx = 0;
        $currenttime = 0;

        foreach ($segmentsxml->Description->MultimediaContent->Video->TemporalDecomposition->VideoSegment as $segmentxml) {
            $regmatches = array();
            preg_match("/PT(\d+M)?(\d+S)(\d+)?(0)?N1000F/", (string) $segmentxml->MediaTime->MediaDuration, $regmatches);
            $sec = substr($regmatches[2], 0, - 1);
            $min = 0;
            $msec = 0;
            if (0 != strcmp('', $regmatches[1])) {
                $min = substr($regmatches[1], 0, - 1);
            }
            if (0 != strcmp('', $regmatches[3])) {
                $msec = $regmatches[3];
            }
            $segment = array();
            $segment['index'] = $currentidx;
            $segment['time'] = $currenttime;
            $text = "";
            if ($segmentxml->SpatioTemporalDecomposition) {
                foreach ($segmentxml->SpatioTemporalDecomposition->VideoText as $textxml) {
                    $text = $text . " " . (string) $textxml->Text;
                }
            }
            $segment['text'] = $text;

            $segment['duration'] = ($min * 60 + $sec) * 1000 + $msec;
            $curmesc = $cursec = $curmin = $remainhour = 0;
            $curmsec = $currenttime % 1000;
            $remainsec = intdiv($currenttime, 1000);
            $cursec = $remainsec % 60;
            $remainmin = intdiv($remainsec, 60);
            $curmin = $remainmin % 60;
            $remainhour = intdiv($remainmin, 60);

            $format = "T%02d:%02d:%02d:%03d";
            $timecode = sprintf($format, $remainhour, $curmin, $cursec, $curmsec);
            $oldformat = "T%02d:%02d:%02d:0";
            $oldtimecode = sprintf($oldformat, $remainhour, $curmin, $cursec);
            if (isset($previewrefs[$timecode])) {
                $attachment = $previewrefs[$timecode];
                preg_match("/track:(.*);time=(.*)F(\d+)/", (string) $attachment['ref'], $regmatches);
                $preview = [];
                $preview["$"] = (string) $attachment['url'];
                $preview["ref"] = $regmatches[1];
            } elseif (isset($previewrefs[$oldtimecode])) {
                $attachment = $previewrefs[$oldtimecode];
                preg_match("/track:(.*);time=(.*)F(\d+)/", (string) $attachment['ref'], $regmatches);
                $preview = [];
                $preview["$"] = (string) $attachment['url'];
                $preview["ref"] = $regmatches[1];
            }

            $previews = [];
            $previews["preview"] = $preview;
            $segment['previews'] = $previews;

            $currentidx ++;
            $currenttime = $currenttime + $segment['duration'];
            array_push($segments['segment'], $segment);
        }
        return $segments;
    }

    /**
     * stores the usertracking data in the database
     *
     * @param ilMatterhornEpisode $episode
     */
    private function putUserTracking(ilMatterhornEpisode $episode)
    {
        global $ilUser;
        $intime = intval($this->params['in']);
        $outtime = intval($this->params['out']);
        $user_id = $ilUser->getId();

        $this->plugin->includeClass("api/class.ilMatterhornUserTracking.php");
        ilMatterhornUserTracking::putUserTracking($user_id, $episode, $intime, $outtime);

        header("HTTP/1.0 204 Stored");
    }

    /**
     * send the Statistic overview for the episode as json.
     *
     * @param ilMatterhornEpisode $episode
     */
    private function sendStatistic(ilMatterhornEpisode $episode)
    {
        $this->plugin->includeClass("api/class.ilMatterhornUserTracking.php");
        $statistic = ilMatterhornUserTracking::getStatisticFromVideo($episode);
        $data = array();
        foreach ($statistic as $name => $value) {
            $content = array();
            $content['name'] = $name;

            $content['type'] = "mapping";
            $content['key'] = "time";
            $content['value'] = "views";
            $content['step'] = 10;
            $arrayKeys = array_keys($value);
            $max = count($arrayKeys) == 0 ? 0 : max($arrayKeys);
            $mapping = array_fill(0, $max, 0);
            $content['mapping'] = array_replace($mapping, $value);

            $data[] = $content;
        }

        $episodeData = $episode->getEpisode();

        $infoarray = array();
        $infoarray['name'] = $episodeData->title;
        $infoarray['episode_id'] = $episode->getEpisodeId();
        $infoarray['series_id'] = $episode->getSeriesId();
        $infoarray['duration'] = strval($episodeData->duration);
        $infoarray['data'] = $data;

        $this->sendJSON($infoarray);
    }

    /**
     * send Footprints for the user
     *
     * @param ilMatterhornEpisode $episode
     */
    private function sendFootprint(ilMatterhornEpisode $episode)
    {
        global $ilUser;
        $user_id = $ilUser->getId();

        $response = array();
        $this->plugin->includeClass("api/class.ilMatterhornUserTracking.php");
        $response['footprints'] = ilMatterhornUserTracking::getFootprints($episode, $user_id);
        $response['last'] = ilMatterhornUserTracking::getLastSecondViewed($episode, $user_id);
        $this->sendJSON($response);
    }

    /**
     * send Statistics like views
     *
     * @param ilMatterhornEpisode $episode
     */
    private function sendStats(ilMatterhornEpisode $episode)
    {
        $response = array();
        $this->plugin->includeClass("api/class.ilMatterhornUserTracking.php");
        $views = ilMatterhornUserTracking::getViews($episode);
        $response['stats'] = [
            'views' => $views
        ];
        $this->sendJSON($response);
    }

    /**
     * send the info/me.json
     */
    private function sendMe()
    {
        $this->plugin->includeClass("api/class.ilMatterhornInfo.php");
        $info = new ilMatterhornInfo();
        $response = $info->getMyInfo();
        $this->sendJSON($response);
    }

    /**
     * send the manager/list.json
     */
    private function sendList()
    {
        $this->plugin->includeClass("api/class.ilMatterhornInfo.php");
        $info = new ilMatterhornInfo();
        $response = $info->listPlugins();
        $this->sendJSON($response);
    }

    /**
     * Send the Array as json
     *
     * @param array $array
     *            the array
     */
    private function sendJSON($array)
    {
        header("Content-Type: application/json");
        echo json_encode($array);
    }

    /**
     * Send an error response for the requested file
     *
     * @param Exception $exception
     */
    public function sendError($exception)
    {
        $errorcode = $exception->getCode();
        $errortext = $exception->getMessage();

        ilLoggerFactory::getLogger('xmh')->debug($errorcode . " " . $errortext);

        http_response_code($errorcode);
        echo $errortext;
        exit();
    }
}