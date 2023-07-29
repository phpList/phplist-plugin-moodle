<?php

/**
 * v0.1 - initial.
 */
class moodle extends phplistPlugin
{
    public $name = 'Moodle plugin';
    public $coderoot = '';
    public $version = '0.1';
    public $authors = 'Michiel Dethmers';
    public $enabled = 1;
    private $developMode = false;
    public $description = 'Synchronise Moodle users to phpList';
    public $documentationUrl = 'https://resources.phplist.com/plugin/moodle';
    private $firstNameAtt = 0;
    private $lastNameAtt = 0;
    public $authProvider = true;
    private $moodleAdminRole = 1;

    private $moodle_table_prefix = 'mdl_';
    public $settings = array(
        'moodle_firstname' => array(
            'description' => 'First Name attribute',
            'type' => 'integer',
            'value' => 0,
            'allowempty' => 1,
            'min' => 1,
            'max' => 9999,
            'category' => 'Moodle',
        ),
        'moodle_lastname' => array(
            'description' => 'Last Name attribute',
            'type' => 'integer',
            'category' => 'Moodle',
            'value' => 0,
            'allowempty' => 1,
            'min' => 1,
            'max' => 9999,
        ),
        'moodle_synchour' => array(
            'description' => 'Hour to run sync before (eg 6 for 6am)',
            'type' => 'integer',
            'category' => 'Moodle',
            'value' => 6,
            'allowempty' => 1,
            'min' => 1,
            'max' => 9999,
        ),
    );

    public function adminmenu()
    {
        return array();
    }

    public function sendFormats()
    {
        return array();
    }

    function __construct() {
      $this->firstNameAtt = getConfig('moodle_firstname');
      $this->lastNameAtt = getConfig('moodle_lastname');
      if (defined('MOODLE_TABLE_PREFIX')) {
        $this->moodle_table_prefix = MOODLE_TABLE_PREFIX;
      }
      if (defined('MOODLE_ADMIN_ROLE')) {
        $this->moodleAdminRole = MOODLE_ADMIN_ROLE;
      }

      ## check that we can auth against Moodle DB
      $query = sprintf('select username, mu.id from %1$suser mu, %1$srole_assignments mra 
        where mu.auth = "manual" and ! mu.suspended and ! mu.deleted and mra.roleid = %2$d and mra.userid = mu.id', 
        $this->moodle_table_prefix,$this->moodleAdminRole);
      Sql_Query($query);
      $count = Sql_Affected_Rows();
      if ($count < 1) {
        $this->authProvider = false;
      }
    }

    /**
     * processQueueStart
     * called at the beginning of processQueue, after the process was locked
     * @param none
     * @return null
     */
    public function processQueueStart()
    {
      $start = $GLOBALS['systemTimer']->interval();
      logEvent('Moodle sync - start '.$start);
      $lastSynced = $this->getConfig('lastsync');
      $syncHour = getConfig('moodle_synchour');
      cl_output('Moodle queue start');
      $syncAge = (int)time() - (int)$lastSynced;
      if ($this->developMode) { ## allow forcing the sync all the time when developing
        $syncAge = 86401;
        $syncHour = 23;
      }
      if ($syncAge > 86400 && date('G') < (int) $syncHour) {
        cl_output('Moodle sync start, last run: '.$lastSynced);
        ## sync Moodle users to phpList that don't exist in phpList
        sql_query(sprintf('REPLACE INTO phplist_user (id, email, confirmed, blacklisted, optedin, disabled, entered, modified, uniqid, uuid, htmlemail)
          SELECT   
              m.id,
              m.email,
              m.confirmed,
              m.suspended as disabled,
              m.policyagreed as optedin,
              m.deleted as blacklisted,
              from_unixtime(m.timecreated) as entered,
              now(),
              md5(concat(now(),m.id)),
              uuid(),
              1
          FROM %suser m
          left join phplist_user p 
          on m.id = p.id
          where p.id is null
        ',$this->moodle_table_prefix));

        ## sync emails that may have changed
        sql_query(sprintf('
          UPDATE IGNORE %s pu
          INNER JOIN %suser mu ON pu.id = mu.id 
          SET 
            pu.email = mu.email, 
            pu.blacklisted = mu.deleted,
            pu.optedin = mu.policyagreed,
            pu.disabled = mu.suspended,
            pu.confirmed = mu.confirmed
          where mu.email != pu.email
        ',$GLOBALS['tables']['user'],$this->moodle_table_prefix));

        # sync first and last name to the configure attributeIDs
        if (!empty($this->firstNameAtt)) {
          $namesQuery = Sql_Query(sprintf('select id,firstname,lastname from %suser',$this->moodle_table_prefix));
          while ($row = Sql_Fetch_Assoc($namesQuery)) {
            Sql_Query(sprintf('replace into %s (attributeid,userid,value) values(%d, %d, "%s")',
              $GLOBALS['tables']['user_attribute'],$this->firstNameAtt,$row['id'],sql_escape($row['firstname'])));
            Sql_Query(sprintf('replace into %s (attributeid,userid,value) values(%d, %d, "%s")',
              $GLOBALS['tables']['user_attribute'],$this->lastNameAtt,$row['id'],sql_escape($row['lastname'])));
          }
        }

        ## get Course Categories
        $courseCategories = array();
        $listCategories = 'Other, Moodle Role';
        $categoriesReq = Sql_Query('select id,name,description from mdl_course_categories');
        while ($row = Sql_Fetch_Assoc($categoriesReq)) {
          $courseCategories[$row['id']] = $row;
          $listCategories .= ', Moodle Course: '.str_replace(',','',$row['name']);
        }

        ## set list categories
        Sql_Query(sprintf('replace into %s (item,value,editable) value("list_categories","%s",0)',
          $GLOBALS['tables']['config'],$listCategories));

        ## create role lists
        $roleQuery = Sql_query(sprintf('select * from %srole',$this->moodle_table_prefix));
        while ($role = Sql_Fetch_Assoc($roleQuery)) {
          $exists = Sql_Fetch_Row_Query(sprintf('select id from %s where name = "Moodle Role: %s"',$GLOBALS['tables']['list'], $role['shortname']));
          if (empty($exists[0])) {
            if (VERBOSE) {
              cl_output('Adding new list Moodle Role: '.$role['shortname']);
            }
            $query = sprintf('insert into %s
              (name,description,entered,listorder,owner,prefix,active,category)
                values("%s","%s",now(),%d,%d,"%s",%d,"%s")',
                $GLOBALS['tables']['list'], 'Moodle Role: '.$role['shortname'], 'Automatically synchronised from Moodle, do not change',
                0, 0,"", 0,'Moodle Role');
            Sql_query($query);
            $listId = Sql_Insert_Id();
           } else {
             if (VERBOSE) {
               cl_output('List Moodle Role: '.$role['shortname'].' already exists');
             }
             $listId = $exists[0];
           }
           ## get people assigned to those roles 
           Sql_Query(sprintf('delete from %s where listid = %d',$GLOBALS['tables']['listuser'],$listId));
           $query = sprintf('select user.id from %1$s user, %2$suser mu, %2$srole_assignments mra 
              where mra.roleid = %3$d and userid = user.id and mu.email = user.email',
              $GLOBALS['tables']['user'],$this->moodle_table_prefix,$role['id']);
            $roleselectQuery = Sql_Query($query);
            while ($row = Sql_fetch_row($roleselectQuery)) {
              Sql_Query(sprintf('replace into %s (userid,listid) values(%d,%d)',$GLOBALS['tables']['listuser'],$row[0],$listId));
            }
        }

        ## create course lists
        foreach ($courseCategories as $categoryId => $category) {
          $courseQuery = Sql_query(sprintf('select * from %scourse where category = %d',$this->moodle_table_prefix,$categoryId));
          while ($course = Sql_Fetch_Assoc($courseQuery)) {
            $exists = Sql_Fetch_Row_Query(sprintf('select id from %s where name = "Moodle Course: %s"',$GLOBALS['tables']['list'], 
              $course['shortname']));
            if (empty($exists[0])) {
              if (VERBOSE) {
                cl_output('Adding new list Moodle Course: '.$course['shortname']);
              }
              $query = sprintf('insert into %s
                (name,description,entered,listorder,owner,prefix,active,category)
                  values("%s","%s",now(),%d,%d,"%s",%d,"%s")',
                  $GLOBALS['tables']['list'], 'Moodle Course: '.$course['shortname'], 'Automatically synchronised from Moodle, do not change',
                  0, 0,"", 0,'Moodle Course: '.str_replace(',','',$category['name']));
              Sql_query($query);
              $listId = Sql_Insert_Id();
            } else {
              if (VERBOSE) {
                cl_output('List Moodle Course: '.$course['shortname'].' already exists');
              }
              $listId = $exists[0];
            }
            ## get people assigned to those courses 
            Sql_Query(sprintf('delete from %s where listid = %d',$GLOBALS['tables']['listuser'],$listId));
            // https://chat.openai.com/share/3244088c-1f77-4ac7-9ac6-a6de9e6f7760
            $query = sprintf('SELECT pu.id, u.email, c.id AS courseid FROM %1$suser u JOIN %1$suser_enrolments ue ON ue.userid = u.id JOIN %senrol e ON e.id = ue.enrolid JOIN %1$scourse c ON c.id = e.courseid join phplist_user pu on pu.email = u.email WHERE c.id = %d;',$this->moodle_table_prefix,$course['id']);
            $courseselectQuery = Sql_Query($query);
            while ($row = Sql_fetch_row($courseselectQuery)) {
              Sql_Query(sprintf('replace into %s (userid,listid) values(%d,%d)',$GLOBALS['tables']['listuser'],$row[0],$listId));
            }
          }
        }
        $this->writeConfig('lastsync',time());
      } else {
        if (VERBOSE) {
          cl_output('Moodle not syncing, sync age: '.$syncAge.' sync Hour: '.$syncHour.' hour now: '.date('G'));
        }
      }
      $end = $GLOBALS['systemTimer']->interval();
      logEvent('Moodle sync - end '.$end);
    }

    /** authentication using Moodle */

    /**
     * validateLogin, verify that the login credentials are correct.
     *
     * @param string $login    the login field
     * @param string $password the password
     *
     * @return array
     *               index 0 -> false if login failed, index of the administrator if successful
     *               index 1 -> error message when login fails
     *
     * eg
     *    return array(5,'OK'); // -> login successful for admin 5
     *    return array(0,'Incorrect login details'); // login failed
     */
    public function validateLogin($login, $password)
    {
        $query = sprintf('select mu.password, mu.id from %1$suser mu, %1$srole_assignments mra 
        where mu.auth = "manual" and ! mu.suspended and ! mu.deleted and mra.roleid = %2$d and mra.userid = mu.id and (username = "%3$s" or email = "%3$s")', 
          $this->moodle_table_prefix, $this->moodleAdminRole, sql_escape($login));
        $req = Sql_Query($query);
        $admindata = Sql_Fetch_Assoc($req);
        $passwordDB = $admindata['password'];

        if (empty($login) || ($password == "")) {
          return array(0, s('Please enter your credentials.'));
        }
        if ($admindata['suspended']) {
          return array(0, s('your account has been suspended'));
        }
        if (
            !empty($passwordDB) && password_verify($password, $passwordDB)
        ) {
          return array($admindata['id'], 'OK');
        }
 
        return array(0, s('incorrect password'));      
    }

    /**
     * validateAccount, verify that the logged in admin is still valid.
     *
     * this allows verification that the admin still exists and is valid
     *
     * @param int $id the ID of the admin as provided by validateLogin
     *
     * @return array
     *               index 0 -> false if failed, true if successful
     *               index 1 -> error message when validation fails
     *
     * eg
     *    return array(1,'OK'); // -> admin valid
     *    return array(0,'No such account'); // admin failed
     */
    public function validateAccount($id)
    {
        $query = sprintf('select mu.id from %1$suser mu, %1$srole_assignments mra 
        where mu.auth = "manual" and ! mu.suspended and ! mu.deleted and mra.roleid = %2$d and mra.userid = mu.id  and mu.id = %3$d', 
          $this->moodle_table_prefix, $this->moodleAdminRole, $id);
        $data = Sql_Fetch_Row_Query($query);
        if (!$data[0]) {
          return array(0, s('No such account'));
        } elseif ($data[1]) {
          return array(0, s('your account has been disabled'));
        }

        //# do this separately from above, to avoid lock out when the DB hasn't been upgraded.
        //# so, ignore the error
        $query = sprintf('select privileges from %s where id = %d', $GLOBALS['tables']['admin'], $id);
        $req = Sql_Query($query);
        if ($req) {
            $data = Sql_Fetch_Row($req);
        } else {
            $data = array();
        }

        if (!empty($data[0])) {
            $_SESSION['privileges'] = unserialize($data[0]);
        }
        return array(1, 'OK');
    }

    /**
     * adminName.
     *
     * Name of the currently logged in administrator
     * Use for logging, eg "subscriber updated by XXXX"
     * and to display ownership of lists
     *
     * @param int $id ID of the admin
     *
     * @return string;
     */
    public function adminName($id)
    {
        $query = sprintf('select firstname, lastname from %suser where auth = "manual" and id = %d', 
          $this->moodle_table_prefix, $id);
        $data = Sql_Fetch_Assoc_Query($query);
        return $data['firstname'] .' '.$data['lastname'];
    }

    /**
     * adminEmail.
     *
     * Email address of the currently logged in administrator
     * used to potentially pre-fill the "From" field in a campaign
     *
     * @param int $id ID of the admin
     *
     * @return string;
     */
    public function adminEmail($id)
    {
        $query = sprintf('select email from %suser where auth = "manual" and id = %d', 
          $this->moodle_table_prefix, $id);
        $data = Sql_Fetch_Assoc_Query($query); 
        return $data['email'];
    }

    /**
     * adminIdForEmail.
     *
     * Return matching admin ID for an email address
     * used for verifying the admin email address on a Forgot Password request
     *
     * @param string $email email address
     *
     * @return ID if found or false if not;
     */
    public function adminIdForEmail($email)
    { 
        ## forgot password needs to be done in Moodle
    }

    /**
     * isSuperUser.
     *
     * Return whether this admin is a super-admin or not
     *
     * @param int $id admin ID
     *
     * @return true if super-admin false if not
     */
    public function isSuperUser($id)
    {
        // Query to be established
        return 1;
    }

    /**
     * listAdmins.
     *
     * Return array of admins in the system
     * Used in the list page to allow assigning ownership to lists
     *
     * @param none
     *
     * @return array of admins
     *               id => name
     */
    public function listAdmins()
    {
        $result = array();
        $query = sprintf('select mu.id, mu.firstname, mu.lastname from %1$suser mu, %1$srole_assignments mra 
          where mra.roleid = %2$d and mra.userid = mu.id',$this->moodle_table_prefix,$this->moodleAdminRole);
        $req = Sql_Query($query);
        while ($row = Sql_Fetch_Array($req)) {
            $result[$row['id']] = $row['firstname'].' '.$row['lastname'];
        }

        return $result;
    }
}
