<?php
/**
 * The model file of user module of XiRangEPS.
 *
 * @copyright   Copyright 2013-2013 QingDao XiRang Network Infomation Co,LTD (www.xirang.biz)
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     user
 * @version     $Id$
 * @link        http://www.xirang.biz
 */
?>
<?php
class userModel extends model
{
    /**
     * Get the user list of an site.
     * 
     * @access public
     * @return array     the users.
     */
    public function getList()
    {
        return $this->dao->select('*')->from(TABLE_USER)->orderBy('account')->fetchAll();
    }

    /**
     * Get the basic info of some user.
     * 
     * @param mixed $users 
     * @access public
     * @return void
     */
    public function getBasicInfo($users)
    {
        $users = $this->dao->select('account, realname, `join`, last, visits, score, love, rank, sect')->from(TABLE_USER)->where('account')->in($users)->fetchAll('account', false);
        if(!$users) return array();
        foreach($users as $account => $user) if($user->realname == '') $user->realname = $account;
        return $users;
    }

    /**
     * Get user by his account.
     * 
     * @param mixed $account   
     * @access public
     * @return object           the user.
     */
    public function getByAccount($account)
    {
        return $this->dao->select('*')->from(TABLE_USER)->where('account')->eq($account)->fetch('', false);
    }

    /**
     * Create a user.
     * 
     * @access public
     * @return void
     */
    public function create()
    {
        if(!$this->checkPassword()) return;

        $user = fixer::input('post')
            ->setDefault('join', date('Y-m-d'))
            ->setDefault('last', helper::now())
            ->setDefault('visits', 1)
            ->setIF($this->post->password1 != false, 'password', md5($this->post->password1))
            ->setIF($this->post->password1 == false, 'password', '')
            ->setIF($this->cookie->r != '', 'referee', $this->cookie->r)
            ->setIF($this->cookie->r == '', 'referee', '')
            ->remove('password1, password2')
            ->get();

        $this->dao->insert(TABLE_USER)->data($user)
            ->autoCheck()
            ->batchCheck($this->config->user->register->requiredFields, 'notempty')
            ->check('account', 'unique', '1=1', false)
            ->check('account', 'account')
            ->checkIF($this->post->email != false, 'email', 'email')
            ->exec();
    }

    /**
     * Update an account.
     * 
     * @param mixed $account 
     * @access public
     * @return void
     */
    public function update($account)
    {
        if(!$this->checkPassword()) return;

        $user = fixer::input('post')
            ->setIF(isset($_POST['join']) and $this->post->join == '', 'join', '0000-00-00')
            ->setIF($this->post->password1 != false, 'password', md5($this->post->password1))
            ->cleanInt('imobile,qq,zipcode')
            ->specialChars('company,address,phone,')
            ->remove('password1, password2')
            ->get();

        $this->dao->update(TABLE_USER)->data($user)
            ->autoCheck()
            ->batchCheck($this->config->user->edit->requiredFields, 'notempty')
            ->checkIF($this->post->email != false, 'email', 'email')
            ->checkIF($this->post->msn != false, 'msn', 'email')
            ->checkIF($this->post->gtalk != false, 'gtalk', 'email')
            ->where('account')->eq($account)
            ->exec(false);
    }

    /**
     * Check the password is valid or not.
     * 
     * @access public
     * @return bool
     */
    public function checkPassword()
    {
        if($this->post->password1 != false)
        {
            if($this->post->password1 != $this->post->password2) dao::$errors['password'][] = $this->lang->error->passwordsame;
            if(!validater::checkReg($this->post->password1, '|(.){6,}|')) dao::$errors['password'][] = $this->lang->error->passwordrule;
        }
        return !dao::isError();
    }
    
    /**
     * Identify a user.
     * 
     * @param   string $account     the account
     * @param   string $password    the password
     * @access  public
     * @return  object              if is valid user, return the user object.
     */
    public function identify($account, $password)
    {
        if(!$account or !$password) return false;

        /* Try account first. */
        $user = $this->dao->select('*')->from(TABLE_USER)
            ->where('account')->eq($account)
            ->andWhere('password')->eq(md5($password))
            ->fetch('', false);
        /* Then try email. */
        if(!$user)
        {
            /* If there are two users using the same email, can't use email to identify. */
            $count = $this->dao->select("count(*) AS count")->from(TABLE_USER)->where('email')->eq($account)->fetch('count', false);
            if($count == 1)
            {
                $user = $this->dao->select('*')->from(TABLE_USER)
                    ->where('email')->eq($account)
                    ->andWhere('password')->eq(md5($password))
                    ->fetch('', false);
            }
        }
        
        if($user)
        {
            $allowTime = $this->dao->select('allowTime')->from(TABLE_USER)
                  ->where('account')->eq($account)
                  ->fetch('allowTime', false);
            $now = helper::now();
            if($allowTime > $now)
            {
                jsonReturn(0, $this->lang->user->alert . ' ' . $this->lang->user->allowTime . ':' . $allowTime->allowTime);
            }

            $ip   = $_SERVER['REMOTE_ADDR'];
            $last = helper::now();
            $this->dao->update(TABLE_USER)
                ->set('visits = visits + 1')
                ->set('ip')->eq($ip)
                ->set('last')->eq($last)
                ->where('account')->eq($account)
                ->exec(false);

            /* Judge is admin or not. */
            $user->isSuper = false;
            if(strpos($this->config->admin->supers, ",$account,") !== false) $user->isSuper = true;
        }
        return $user;
    }

    /**
     * Authorize a user.
     * 
     * @param   string $account   the account
     * @access  public
     * @return  array             the priviledges.
     */
    public function authorize($account)
    {
        $account = filter_var($account, FILTER_SANITIZE_STRING);
        if(!$account) return false;

        $rights = array();
        if($account == 'guest')
        {
            $sql = $this->dao->select('module, method')->from(TABLE_GROUP)->alias('t1')->leftJoin(TABLE_GROUPPRIV)->alias('t2')
                ->on('t1.id = t2.group')->where('t1.name')->eq('guest');
        }
        else
        {
            $sql = $this->dao->select('module, method')->from(TABLE_USERGROUP)->alias('t1')->leftJoin(TABLE_GROUPPRIV)->alias('t2')
                ->on('t1.group = t2.group')
                ->where('t1.account')->eq($account);
        }
        $stmt = $sql->query();
        if(!$stmt) return $rights;
        while($row = $stmt->fetch(PDO::FETCH_ASSOC))
        {
            $rights[strtolower($row['module'])][strtolower($row['method'])] = true;
        }
        return $rights;
    }

    /**
     * Juage a user is logon or not.
     * 
     * @access public
     * @return bool
     */
    public function isLogon()
    {
        return (isset($_SESSION['user']) and !empty($_SESSION['user']) and $_SESSION['user']->account != 'guest');
    }

    /**
     * Get users List 
     *
     * @param object  $pager
     * @param string  $userName
     * @access public
     * @return object 
     */
    public function getUsers($pager, $userName = '')
    {
        return $this->dao->select('*')->from(TABLE_USER)
            ->beginIF($userName != '')->where('account')->like("%$userName%")->fi()
            ->orderBy('id_asc')->page($pager)->fetchAll();
    }

    /**
     * Forbid the user
     *
     * @param string $date
     * @param int $userID
     * @access public
     * @return void
     */
    public function forbid($date, $userID)
    {
        switch($date)
        {
            case "oneday"   : $intdate = strtotime("+1 day");break;
            case "twodays"  : $intdate = strtotime("+2 day");break;
            case "threedays": $intdate = strtotime("+3 day");break;
            case "oneweek"  : $intdate = strtotime("+1 week");break;
            case "onemonth" : $intdate = strtotime("+1 month");break;
            case "forever"  : $intdate = strtotime("+10 years");break;
        }
        $format = 'Y-m-d H:i:s';

        $date = date($format,$intdate);
        $this->dao->update(TABLE_USER)->set('allowTime')->eq($date)->where('id')->eq($userID)->exec();
    }

    /**
     * Identify email to regain the forgotten password 
     *
     * @access  public
     * @param   string account
     * @param   string email
     * @return  object              if is valid user, return the user object.
     */
    public function checkEmail($account, $email)
    {
        if(!$account or !$email) return false;

        if(RUN_MODE == 'admin' and strpos($this->config->admin->users, ",$account,") === false) return false;

        $user = $this->dao->select('*')->from(TABLE_USER)
            ->where('account')->eq($account)
            ->andWhere('email')->eq($email)
            ->fetch('', false);
        return $user;
    } 

    /**
     * update the resetKey.
     * 
     * @param  string   $resetKey 
     * @param  time     $resetedTime 
     * @access public
     * @return void
     */
    public function resetKey($account, $resetKey)
    {
        $this->dao->update(TABLE_USER)->set('resetKey')->eq($resetKey)->set('resetedTime')->eq(helper::now())->where('account')->eq($account)->exec(false);
    }

    /**
     * Check the resetKey.
     * 
     * @param  string   $resetKey 
     * @param  time     $resetedTime 
     * @access public
     * @return void
     */
    public function checkResetKey($resetKey)
    {
        $user = $this->dao->select('*')->from(TABLE_USER)
            ->where('resetKey')->eq($resetKey)
            ->fetch('', false);
        return $user;
    }

    /**
     * Reset the forgotten password.
     * 
     * @param  string   $resetKey 
     * @param  time     $resetedTime 
     * @access public
     * @return void
     */
    public function resetPassword($resetKey, $password)
    {
        $this->dao->update(TABLE_USER)->set('password')->eq(md5($password))->set('resetKey')->eq('')->set('resetedTime')->eq('')->where('resetKey')->eq($resetKey)->exec(false);
    }

    public function switchLevel($user)
    {
        $level = 0;
        $userConfig = $this->config->user;
        if(!isset($userConfig->level)) return $user;
        krsort($userConfig->level);
        foreach($userConfig->level as $levelIndex => $rank)
        {
            if($user->rank > $rank)
            {
                $level = $levelIndex;
                break;
            }
        }

        if($level == 0) $level = 1;
        $user->level = $level;
        return $user;
    }
}
