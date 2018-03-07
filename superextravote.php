<?php
/**
 * @package    superExtraVote
 *
 * @author     Artem Vasilev <kern.usr@gmail.com>
 * @copyright  A copyright
 * @license    GNU General Public License version 3 or later; see LICENSE.txt
 * @link       https://awesomio.org
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;

defined('_JEXEC') or die;

class plgAjaxSuperExtraVote extends CMSPlugin
{
	/**
	 * @var    \JApplication
     * @since   3.8.0
	 */
	protected $app;

	/**
	 * @var    \JDatabaseDriver
     * @since    3.8.0
	 */
	protected $db;

	/**
	 * @var    boolean
     * @since    3.8.0
	 */
	protected $autoloadLanguage = true;

    /**
     * @var    string
     * @since    3.8.0
     */
    protected $votingPosition;

    /**
     * @var    int
     * @since    3.8.0
     */
    protected $article_id;

    /**
     * @var    \JHtmlUser
     * @since    3.8.0
     */
    protected $user;

    /**
     * @var    \Joomla\CMS\Document\HtmlDocument
     * @since    3.8.0
     */
    protected $doc;

    /**
     * @property \JInput input
     * @since    3.8.0
     */
    protected $input;
    /**
     * @property int
     * @since    3.8.0
     */
    protected $rating;

    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->app = Factory::getApplication();
        $this->db = Factory::getDbo();
        $this->user = Factory::getUser();
        $this->doc = Factory::getDocument();
        $this->input = $this->app->input;
    }

    /**
     * @return  string
     *
     * @since   3.8.0
     */
    public function onAjaxSuperExtraVote()
    {
        $this->article_id = $this->input->getVar('article', '');
        $this->rating = $this->input->getVar('rating', '');

        $result = array(
            'status' => '',
            'data' => array()
        );

        if(!$this->article_id || !$this->rating){
            $result['status'] = 'error';
            return json_encode($result);
        }

        $access = $this->checkAccess();
        if(!$access){
            $result['status'] = $this->user->id ? 'alreadyvoted' : 'needauth';
            return json_encode($result);
        }

        $vote = $this->getVotingData();

        $vote['rating_sum'] = (int) $vote['rating_sum'] + (int) $this->rating;
        $vote['rating_count'] = (int) $vote['rating_count'] + 1;

        if(!$this->setVote($vote)){
            $result['status'] = 'error';
            return json_encode($result);
        }

        $vote['rating'] = ceil(($vote['rating_sum'] / $vote['rating_count'])/0.5)*0.5;

        $result['status'] = 'success';
        $result['data'] = $vote;

        //$result = $this->article_id .'->'. $this->rating;

        return json_encode($result);
    }

    private function getVotingData(){

        $query=$this->db->getQuery(true);
        $query->select(
            $this->db->quoteName(array('rating_sum', 'rating_count', 'content_id'))
        )
            ->from($this->db->quoteName('#__content_rating'))
            ->where($this->db->quoteName('content_id') .' = '. $this->db->quote($this->article_id));

        $this->db->setQuery($query);
        $vote=$this->db->loadAssoc();
        $query->clear();

        if(!$vote){
            $vote = array(
                'rating_sum' => 0,
                'rating_count' => 0,
                'content_id' => $this->article_id,
                'rating_not_exist' => true
            );
        }

        $vote['rating_sum'] = intval($vote['rating_sum']);
        $vote['rating_count'] = intval($vote['rating_count']);

        return $vote;
    }

    /**
     *
     * @return bool
     *
     * @since 3.8
     */
    private function checkAccess(){
        if($this->user->id == 0)
            return false;
        $query=$this->db->getQuery(true);
        $query->select('COUNT(*)')
            ->from($this->db->quoteName('#__content_superextravote'))
            ->where(
                $this->db->quoteName('user_id') .'='. $this->user->id .' AND '.
                $this->db->quoteName('content_id') .'='. $this->article_id
            );
        $this->db->setQuery($query);
        $count = $this->db->loadResult();
        $query->clear();

        return $count ? false : true;
    }

    private function setVote($data){
        $query = $this->db->getQuery(true);

        if(isset($data['rating_not_exist']) && $data['rating_not_exist']){
            $query
                ->insert($this->db->quoteName('#__content_rating'))
                ->columns($this->db->quoteName(array('content_id', 'rating_sum', 'rating_count', 'lastip')))
                ->values(implode(',', array($this->article_id, $data['rating_sum'], $data['rating_count'],
                    $this->db->quote($_SERVER['REMOTE_ADDR']))));
        } else {
            $query
                ->update($this->db->quoteName('#__content_rating'))
                ->set( 'rating_sum = ' . $data['rating_sum'])
                ->set( 'rating_count = ' . $data['rating_count'])
                ->set( 'lastip = ' . $this->db->quote($_SERVER['REMOTE_ADDR']))
                ->where('content_id = '.$this->article_id);
        }

        $this->db->setQuery($query);

        try
        {
            $this->db->execute();
        }

        catch (RuntimeException $e)
        {
            return false;

        }

        $query->clear();

        $query
            ->insert($this->db->quoteName('#__content_superextravote'))
            ->columns($this->db->quoteName(array('content_id', 'user_id')))
            ->values(implode(',', array($this->article_id, $this->user->id)));
        $this->db->setQuery($query);

        try
        {
            $this->db->execute();
        }

        catch (RuntimeException $e)
        {
            return false;

        }

        return true;
    }
}