<?php

/**
 *
 * @author Leon Kiefer <leon.kiefer@tik.uni-stuttgart.de>
 */
class ilMatterhornSeries
{

    /**
     * The Opencast series id, not the ilias object id
     *
     * @var string
     */
    private $series_id;

    /**
     *
     * @param string $series_id
     */
    public function __construct($series_id)
    {
        $this->series_id = $series_id;
    }

    /**
     *
     * @return string
     */
    public function getSeriesId()
    {
        return $this->series_id;
    }

    /**
     *
     * @return string
     */
    public function getQuoteSeriesId()
    {
        global $ilDB;
        return $ilDB->quote($this->getSeriesId(), "string");
    }

    /**
     *
     * @return array
     */
    public function getSeriesInformationFromOpencast()
    {
        $plugin = ilPlugin::getPluginObject(IL_COMP_SERVICE, 'Repository', 'robj', 'Matterhorn');
        $plugin->includeClass("opencast/class.ilOpencastAPI.php");
        $series = ilOpencastAPI::getInstance()->getSeries($this->getSeriesId());
        $series = array(
            "title" => (string) $series["title"],
            "description" => (string) $series["description"],
            "publishers" => (array) $series["publishers"],
            "identifier" => (string) $series["identifier"]
        );
        return $series;
    }

    /**
     * The scheduled information for this series returned by opencast
     *
     * @return array the scheduled episodes for this series returned by opencast
     */
    public function getScheduledEpisodes()
    {
        $plugin = ilPlugin::getPluginObject(IL_COMP_SERVICE, 'Repository', 'robj', 'Matterhorn');
        $plugin->includeClass("opencast/class.ilOpencastAPI.php");
        return ilOpencastAPI::getInstance()->getScheduledEpisodes($this->getSeriesId());
    }

    /**
     * Get the episodes which are on hold for this series
     *
     * @return array the episodes which are on hold for this series returned by opencast
     */
    public function getOnHoldEpisodes()
    {
        $plugin = ilPlugin::getPluginObject(IL_COMP_SERVICE, 'Repository', 'robj', 'Matterhorn');
        $plugin->includeClass("opencast/class.ilOpencastAPI.php");
        return ilOpencastAPI::getInstance()->getOnHoldEpisodes($this->getSeriesId());
    }

    /**
     * Get the episodes which are on hold for this series
     *
     * @return array the episodes which are on hold for this series returned by opencast
     */
    public function getProcessingEpisodes()
    {
        $plugin = ilPlugin::getPluginObject(IL_COMP_SERVICE, 'Repository', 'robj', 'Matterhorn');
        $plugin->includeClass("opencast/class.ilOpencastAPI.php");
        return ilOpencastAPI::getInstance()->getProcessingEpisodes($this->getSeriesId());
    }
}