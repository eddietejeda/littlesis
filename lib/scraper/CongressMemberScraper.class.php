<?php

class CongressMemberScraper extends Scraper
{
  protected $_searchUrl = 'http://bioguide.congress.gov/biosearch/biosearch1.asp';
  protected $_profileUrlBase = 'http://bioguide.congress.gov/scripts/biodisplay.pl?index=';
  protected $_govtrackUrlBase = 'http://www.govtrack.us/congress/person.xpd?id=';
  protected $_watchdogUrlBase = 'http://watchdog.net/';
  protected $_pvsUrlBase = 'http://votesmart.org/bio.php?can_id=';
  protected $_source = 'http://bioguide.congress.gov';
  protected $_sessions; 
  protected $_houseEntityId;
  protected $_houseEntityName = 'US House of Representatives';
  protected $_senateEntityId;
  protected $_senateEntityName = 'US Senate';
  protected $_sessionListId;
  protected $_rows;
  protected $_existingSessionMemberIds = array();
  protected $_completeSessionOnFinish = true;
  protected $_sessionStartYear = null;
  protected $_sunlightUrlBase = null;
  
  
  //holders for temporary single member data
  protected $_bioPageText;
  protected $_image;
  protected $_senateRelationships = array();
  protected $_houseRelationships = array();
  protected $_committeeRelationships = array();
  protected $_schoolRelationships = array();
  protected $_references = array();
  protected $_committees = array();
  protected $_staffers = array();
  protected $_office;
  protected $_offices = array();
  protected $_officeStafferRelationships = array();

  
  //set numbered congressional sessions to scrape
  public function setSessions($ary)
  {
    $this->_sessions = (array) $ary;
  }


  public function setHouseEntityId($id)
  {
    $this->_houseEntityId = $id;
  }
  
  
  public function setSenateEntityId($id)
  {
    $this->_sentateEntityId = $id;
  }
  
  
  public function execute()
  {
    require sfConfig::get('sf_lib_dir') . '/vendor/votesmart/VoteSmart.php';
    $this->_sunlightUrlBase = 'http://services.sunlightlabs.com/api/legislators.get.xml?apikey=' . sfConfig::get('app_sunlight_api_key') . '&all_legislators=true&bioguide_id=';
    
    if (!$this->safeToRun('congress'))
    {
      $this->printDebug('script already running');
      die;
    }
    

    //establish house id
    if (!$this->_houseEntityId)
    {
      if (!$house = Doctrine::getTable('Entity')->findOneByName($this->_houseEntityName))
      {
        $house = new Entity;
        $house->addExtension('Org');
        $house->addExtension('GovernmentBody');
        $house->name = $this->_houseEntityName;
        $house->save();
      }      
      $this->_houseEntityId = $house->id;
    }
    
    
    //establish senate id
    if (!$this->_senateEntityId)
    {  
      if (!$senate = Doctrine::getTable('Entity')->findOneByName($this->_senateEntityName))
      {
        $senate = new Entity;
        $senate->addExtension('Org');
        $senate->addExtension('GovernmentBody');
        $senate->name = $this->_senateEntityName;
        $senate->save();
      }  
      $this->_senateEntityId = $senate->id;
    }
    
    

    //loop through sessions
    while ($session = current($this->_sessions))
    { 
      //make sure session hasn't already been scraped
      if ($this->hasMeta($session, 'is_complete') && $this->getMeta($session, 'is_complete'))
      {
        $this->printDebug("\n\nSession " . $session . " members already scraped; skipping");

        next($this->_sessions);
        continue;
      }
      if (!$this->_sessionListId)
      {
        $sessionName = $session . 'th Congress';
        if (!$sessionList = Doctrine::getTable('LsList')->findOneByName($sessionName))
        {
          $sessionList = new LsList;
          $sessionList->name = $sessionName;
          $sessionList->save();
        }
        $this->_sessionListId = $sessionList->id;
      }
    

      $this->printDebug("\n\nFetching bios for congress session: " . $session . "\n");


      $this->loadExistingSessionMembers($session);
      $this->printDebug("\n\ndone loading session members: " . $session . "\n");
      
      $this->scrapeCongressSessionMembers($session);
     
      if ($this->_completeSessionOnFinish)
      {
        //end relationships for discontinuing members only after all current members have been processed
        $this->updateDiscontinuingMemberRelationships($session);

        $this->saveMeta($session, 'is_complete', true);
        $this->removeMeta($session, 'last_processed');
        
        $this->printDebug("\n\nSession " . $session . " completed");
      }
      
      $this->resetSessionVariables();

      
      next($this->_sessions);
    }  
  }


  //find all entities that have been tagged with congress:session:$session triple, and store
  //them so we know when to skip previously-scraped entities for that session
  public function loadExistingSessionMembers($session)
  {
    $q = LsQuery::getByModelAndFieldsQuery('ObjectTag', array(
      'object_model' => 'Entity'
    ))->select('objecttag.object_id');
    
    $results = $q->leftJoin('objecttag.Tag t')
      ->addWhere('t.triple_namespace = ? AND t.triple_predicate = ? AND t.triple_value = ?', array('congress', 'session', $session))
      ->fetchArray();
        
    foreach ($results as $ary)
    {
      $this->_existingSessionMemberIds[] = (int) $ary['object_id'];
    }    
  }
  
  //used to correct a previous scraper mess-up, where sunlight and pvs didn't run due to lack of API key
  public function updateExisting($offset)
  {
    $this->_sunlightUrlBase = 'http://services.sunlightlabs.com/api/legislators.get.xml?apikey=' . sfConfig::get('app_sunlight_api_key') . '&all_legislators=true&bioguide_id=';
    foreach($this->_sessions as $session)
    {
      $q = LsQuery::getByModelAndFieldsQuery('ObjectTag', array(
          'object_model' => 'Entity'
           ))->select('objecttag.object_id');
      
      $results = $q->leftJoin('objecttag.Tag t')
       ->addWhere('t.triple_namespace = ? AND t.triple_predicate = ? AND t.triple_value = ?', array('congress', 'session', $session))
        ->fetchArray();
      $lim = count($results);
      if ($this->limit && $this->limit < count($results) - $offset)
      {
        $lim = $this->limit;
      }
        
      for($i = $offset; $i < $lim; $i++)
      {
        try
        {
          $this->db->beginTransaction();
  
  
          $ary = $results[$i];
          $member = Doctrine::getTable('Entity')->find((int) $ary['object_id']);
          echo $i . ": " . $member->name . "\n";
          //get sunlight data for each member
          $this->getSunlightData($member);
          
          //get pvs data for each member
          $this->getPvsData($member);
          
          $modified = $member->getAllModifiedFields();
          $member->save();
          
          $this->printDebug("Saved member with entity ID: " . $member->id);
          
    
          foreach ($this->_references as $key => $ref)
          {
            $ref->object_model = 'Entity';
            $ref->object_id = $member->id;
            $ref->save();
          }
          
          /*
          foreach ($this->_schoolRelationships as $rel)
          {
            $modified = $rel->getAllModifiedFields();
            $rel->save();
            
            $rel->addReference($this->_pvsUrlBase . $member->pvs_id, null, $modified, 'Project Vote Smart');
          }      

          foreach ($this->_offices as $address)
          {
            $modified = $address->getAllModifiedFields();
            if ($member->addAddress($address))
            {
              $this->printDebug("Added office address: " . $address);
              $address->save();
              $address->addReference($this->_pvsUrlBase . $member->pvs_id, null, $modified, 'Project Vote Smart');
            }        
          }*/
          
          /*
          //find matches
          $match = LsDoctrineQuery::create()
          ->from('Entity e')
          ->leftJoin('e.PoliticalCandidate pc')
          ->where('((pc.house_fec_id = ? and pc.house_fec_id is not null and pc.house_fec_id <> ?) or (pc.senate_fec_id = ? and pc.senate_fec_id is not null and pc.senate_fec_id <> ?) or (pc.pres_fec_id is not null and pc.pres_fec_id = ? and pc.pres_fec_id <> ?)) and e.id <> ?', array($member->house_fec_id,"",$member->senate_fec_id,"", $member->pres_fec_id,"",$member->id))
          ->fetchOne();
          
          if ($match)
          {
            $this->printDebug("\n\n\t\t\tMERGING MERGING\n\n");
            $this->printDebug("Member found in database with name " . $match->name . " and entity ID " . $match->id);
            if ($match->id < $member->id)
            {
              $merged_member = EntityTable::mergeAll($match, $member);
              $member->setMerge(true);
  
              //clear relations so merged relations aren't deleted
              $member->clearRelated();
              $member->delete();
            }
            else
            {
              $merged_member = EntityTable::mergeAll($member,$match);
              $match->setMerge(true);
  
              //clear relations so merged relations aren't deleted
              $match->clearRelated();
              $match->delete();
            }
            $merged_member->save();
            
          }*/
          $this->resetMemberVariables();
          echo "\n";
          if (!$this->testMode)
          {
            $this->db->commit();
          }
        }
        catch (Exception $e)
        {
          $this->db->rollback();
          throw $e;
        } 
      }
    }
  }


  public function scrapeCongressSessionMembers($session)
  {
  
    if ($this->cookieBrowser->post($this->_searchUrl, array('congress' => $session))->responseIsError())
    {
      //Error response (eg. 404, 500, etc)
      throw new Exception("Couldn't get " . $url);
    }  

    $text = $this->cookieBrowser->getResponseText();    

    //get rows of conress members
    preg_match_all('/<tr><td><A HREF="http:\/\/bioguide\.congress\.gov\/scripts\/biodisplay\.pl\?index=([^"]+)">([^<]+)<\/A><\/td><td>([^<]+)<\/td>\s*<td>([^<]+)<\/td><td>([^<]+)<\/td><td align="center">([^<]+)<\/td><td align="center">[^<]+<br>\(([^<]+)\)<\/td><\/tr>/imsU', $text, $rows, PREG_SET_ORDER);

    $this->printDebug("Found " . count($rows) . " members in this session");



    //if last_processed is set, skip rows until it's passed
    //implicit assumption here is that rows will be in the same order as last time
    if ($this->hasMeta($session, 'last_processed'))
    {
       $lastProcessed = $this->getMeta($session, 'last_processed');
       $skip = true;
    }
    else
    {
      $skip = false;
    }

    //$skip = false;
    
    foreach ($rows as $row)
    {
      if ($skip)
      {
        if ($row[1] == $lastProcessed)
        {
          $skip = false;
          continue;
        }
        else
        {
          continue;
        }
      }

      $this->_rows[] = new CongressMemberScraperRow($row);
    }


    //set session start year in order to end discontinuing congress member relationships
    $this->_sessionStartYear = $this->_rows[0]->termStart;
    


    //splice rows if given limit
    if ($this->limit && $this->limit < count($this->_rows))
    {
      $this->_rows = array_slice($this->_rows, 0, $this->limit);

      //since we're not processing all rows, don't mark the session as complete when we're done
      $this->_completeSessionOnFinish = false;      
    }



    $this->printDebug("Processing " . count($this->_rows) . " members");


    //process rows
    while ($row = current($this->_rows))
    {
      $member = $this->processCongressMemberRow($row);
      if ($member) 
      {
        //echo 'ok';
        $this->addListMember($member);
      }
      
      $this->resetMemberVariables();
                      
      $this->saveMeta($session, 'last_processed', $row->id);
      
      next($this->_rows);        
    }
  }


  public function resetSessionVariables()
  {
    $this->_completeSessionOnFinish = true;
    $this->_existingSessionMemberIds = array();
    $this->_rows = array();
    $this->_sessionStartYear = null;
  }


  public function resetMemberVariables()
  {
    $this->_bioPageText = null;
    $this->_image = null;
    $this->_senateRelationships = array();
    $this->_houseRelationships = array();
    $this->_committeeRelationships = array();
    $this->_schoolRelationships = array();
    $this->_committees = array();
    $this->_references = array();
    $this->_staffers = array();
    $this->_offices = array();
    $this->_officeRelationships = array();
    $this->_officeStafferRelationships = array();
  }


  public function processCongressMemberRow($row)
  {
    $this->_references['bioguide'] = new Reference;

    $this->printDebug("\nProcessing member with name " . $row->name . " and ID " . $row->id);
    

    try
    {
      //we have to begin the transaction here because matchInDatabase might merge Entities and save
      $this->db->beginTransaction();


      //check that congress member isn't a repeat
      $member = EntityTable::getByExtensionQuery('ElectedRepresentative')  
        ->addWhere('electedrepresentative.bioguide_id = ?', $row->id)
        ->fetchOne();
  
  
      //if member hasn't been imported already as a member of congress, 
      //create with all bio info and look for a match
      
      if (!$member)
      {
        $member = $this->importNewMember($row);
        $member = $this->matchInDatabase($member);
        /*
        if(!$merged_member = $this->matchInDatabase($member))
        {
          $redundant_member = false;
        }
        else
        {
          $redundant_member = $member;
          $member = $merged_member;
        }  */
      }
      else
      {
        $this->printDebug("Member exists in database with entity ID: " . $member->id);
        
        
        //if member is tagged with this session, skip
        if (in_array($member->id, $this->_existingSessionMemberIds))
        {
          $this->db->rollback();
          $this->printDebug("Member has already been tagged with session " . current($this->_sessions) . "; skipping");
          return $member;
        }


        //update member's bio
        $this->updateBio($member);
        $this->printDebug("Updated member bio");


        //check if member is continuing from previous session
        $q = $member->getTripleTagsQuery('congress', 'session')->addWhere('tag.triple_value = ?', current($this->_sessions) - 1);

        if ($q->count())
        {
          $this->printDebug("Continuing member from previous session...");


          //update committee relationships -- not used right now, use 
          //govtrack:parse-congress-committees instead
          //$this->updatePvsCommittees($member);


          //if member continuing, look for relationship with opposite chamber to end,
          //in case the member's switched chambers
          $oppositeChamberId = ($row->type == 'Senator') ? $this->_houseEntityId : $this->_senateEntityId;
          $q = LsDoctrineQuery::create()
            ->from('Relationship r')
            ->where('r.entity1_id = ? AND r.entity2_id = ?', array($member->id, $oppositeChamberId))
            ->andWhere('r.category_id = ? AND r.end_date IS NULL', RelationshipTable::MEMBERSHIP_CATEGORY);
          
          foreach ($q->execute() as $rel)
          {
            $rel->end_date = ($this->_sessionStartYear - 1) . '-00-00';
            $rel->is_current = false;
            $rel->save();
            
            $this->printDebug("Ended relationship " . $rel->id . " with opposite chamber from previous session");
          }
          
          //if no current relationships with same chamber, create one
          $thisChamberId = ($row->type == 'Senator') ? $this->_senateEntityId : $this->_houseEntityId;
          $q = LsDoctrineQuery::create()
            ->from('Relationship r')
            ->where('r.entity1_id = ? AND r.entity2_id = ?', array($member->id, $thisChamberId))
            ->andWhere('r.category_id = ? AND r.end_date IS NULL', RelationshipTable::MEMBERSHIP_CATEGORY);

          if (!$q->count())
          {
            $this->printDebug("No relationships with this chamber from previous session; creating new one...");

            $r = new Relationship;
            $r->entity1_id = $member->id;
            $r->entity2_id = ($row->type == 'Senator') ? $this->_senateEntityId : $this->_houseEntityId;
            $r->setCategory('Membership');
            $r->description1 = $row->type;
            $r->start_date = $row->termStart . '-00-00';
            $r->is_current = true;
            
            if ($row->type = 'Senator')
            {
              $this->_senateRelationships[] = $r;
            }
            else
            {
              $this->_houseRelationships[] = $r;          
            }
          }
        }
        else
        {
          $this->printDebug("Member not continuing from previous session; creating new relationship...");

          //if member not continuing, add a new relationship for this session and chamber
          $r = new Relationship;
          $r->entity1_id = $member->id;
          $r->entity2_id = ($row->type == 'Senator') ? $this->_senateEntityId : $this->_houseEntityId;
          $r->setCategory('Membership');
          $r->description1 = $row->type;
          $r->start_date = $row->termStart . '-00-00';
          $r->is_current = true;
          
          if ($row->type = 'Senator')
          {
            $this->_senateRelationships[] = $r;
          }
          else
          {
            $this->_houseRelationships[] = $r;          
          }
        }        
      }

      
      //set party name
      $partyName = null;
      
      $blurb = '';
      
      if ($party = $row->party)
      {
      
        if ($party == 'Democrat')
        {
          $blurb .= $party . 'ic';
          $partyName = 'Democratic Party';
        }
        elseif ($party == 'Independent')
        {
          $blurb .= $party;
          $partyName = null;
        }
        else
        {
          $blurb .= $party;
          $partyName = $party . ' Party';
        }


        //if party entity doesn't exist, create one
        if ($partyName)
        {
          $q = EntityTable::getByExtensionQuery('PoliticalParty')
            ->addWhere('e.name = ?', $partyName);

          if (!$partyEntity = $q->fetchOne())
          {
            $partyEntity = new Entity;
            $partyEntity->addExtension('Org');
            $partyEntity->addExtension('PoliticalParty');
            $partyEntity->name = $partyName;
            $partyEntity->save();
            
            $this->printDebug("Created new political party: " . $partyName);
          }
        }


        //create current party affiliation if session is member's most recent session
        if ($member->exists())
        {
          $q = $member->getTripleTagsQuery('congress', 'session')->addWhere('tag.triple_value > ?', current($this->_sessions));
        
          $setParty = $q->count() ? false : true;
        }
        else
        {
          $setParty = true;
        }
        
        if ($setParty)
        {
          if ($partyName)
          {
            $member->Party = $partyEntity;
            $member->is_independent = false;
            
            $this->printDebug("Set current political affiliation to " . $partyName);
          }
          else
          {
            $member->is_independent = true;
            $member->party_id = null;

            $this->printDebug("Set current political affiliation to Independent");
          }        
        }
      }

      
      //save member
      $modified = $member->getAllModifiedFields();
      $member->save();
      
      $this->printDebug("Saved member with entity ID: " . $member->id);
  
      $this->addListMember($member);
      
      //set member reference fields
      $excludeFields = array();

      foreach ($this->_references as $key => $ref)
      {
        $ref->object_model = 'Entity';
        $ref->object_id = $member->id;

        if ($key != 'bioguide')
        {
          $ref->save();
          $excludeFields = array_merge($excludeFields, $ref->getFieldsArray());
        }

      }
      
      $modified = array_diff($modified, $excludeFields);
      
      $this->_references['bioguide']->addFields($modified);
      $this->_references['bioguide']->save();      

      $this->printDebug("Saved member references");

      

      //tag member with congress session
      $member->addTagByTriple('congress', 'session', current($this->_sessions));

      $this->printDebug("Added tag for session " . current($this->_sessions));


      //save image, if any
      if ($this->_image)
      {
        $this->_image->Entity = $member;
        $this->_image->save();

        $this->printDebug("Saved member image");


        if ($this->_photoUrl)
        {
          //save image source
          $this->_image->addReference($this->_photoUrl);
          
          $this->printDebug("Saved image reference");
        }
      }


      //save school relationships (and the schools)   
      foreach ($this->_schoolRelationships as $rel)
      {
        $modified = $rel->getAllModifiedFields();
        $rel->save();
        
        $rel->addReference($this->_pvsUrlBase . $member->pvs_id, null, $modified, 'Project Vote Smart');
      }      
      
      //save staffers
      foreach ($this->_staffers as $staffer)
      {
        $modified = $staffer->getAllModifiedFields();
        $staffer->save();
        
        $staffer->addReference($this->_pvsUrlBase . $member->pvs_id, null, $modified, 'Project Vote Smart');
      }
            
      
      //save office relationships
      foreach ($this->_offices as $address)
      {
        $modified = $address->getAllModifiedFields();
        if ($member->addAddress($address))
        {
          $this->printDebug("Added office address: " . $address);
          $address->save();
          $address->addReference($this->_pvsUrlBase . $member->pvs_id, null, $modified, 'Project Vote Smart');
        }        
      }

      //save office staffer relationships
      foreach ($this->_officeStafferRelationships as $rel)
      {
        $modified = $rel->getAllModifiedFields();
        $rel->save();
        
        $rel->addReference($this->_pvsUrlBase . $member->pvs_id, null, $modified, 'Project Vote Smart');
      }

      //save committee relationships, if any
      foreach ($this->_committeeRelationships as $rel)
      {
        $modified = $rel->getAllModifiedFields();
        $rel->save();

        $rel->addReference($this->_govtrackUrlBase . $member->govtrack_id, null, $modified, 'GovTrack.us');
      }

      //create party membership relationships        
      if ($partyName)
      {        
        //if membership relationship with party doesn't exist, create it
        $partyRel = LsQuery::getByModelAndFieldsQuery('Relationship', array(
          'entity1_id' => $member->id,
          'entity2_id' => $partyEntity->id,
          'category_id' => RelationshipTable::MEMBERSHIP_CATEGORY
        ))->fetchOne();
        
        if (!$partyRel)
        {
          $partyRel = new Relationship;
          $partyRel->Entity1 = $member;
          $partyRel->Entity2 = $partyEntity;
          $partyRel->setCategory('Membership');

          $modified = $partyRel->getAllModifiedFields();
          $partyRel->save();
          
          $partyRel->addReference($this->_profileUrlBase . $member->bioguide_id, null, $modified, 'Congressional Biographical Directory');
          
          $this->printDebug("Created membership in political party: " . $partyName);
        }
      }
                          
      $senator = null;
      

      //create senate relationships
      foreach ($this->_senateRelationships as $rel)
      {
        $modified = $rel->getAllModifiedFields();
        $rel->save();
        
        $rel->addReference($this->_profileUrlBase . $member->bioguide_id, null, $modified, 'Congressional Biographical Directory');

        $this->printDebug("Saved Senate relationship");      
      }


      //create house relationships
      foreach ($this->_houseRelationships as $rel)
      {
        $modified = $rel->getAllModifiedFields();
        $rel->save();

        $rel->addReference($this->_profileUrlBase . $member->bioguide_id, null, $modified, 'Congressional Biographical Directory');

        $this->printDebug("Saved House relationship");       
      }

      //save everything
      if (!$this->testMode)
      {
        $this->db->commit();
      }
    }
    catch (Exception $e)
    {
      $this->db->rollback();
      throw $e;
    }  
    /*if(isset($redundant_member) && $redundant_member)
    {
      $redundant_member->setMerge(true);
      //clear relations so merged relations aren't deleted
      $redundant_member->clearRelated();
      $redundant_member->delete();
      echo "REDUNDANT MEMBER DELETED\n";
    }*/
  }


  protected function importNewMember($row)
  {
    $this->printDebug("Creating new member");

    $member = self::parseBioguideName($row->name);
    $member->addExtension('ElectedRepresentative');
    $member->addExtension('PoliticalCandidate');
    $member->bioguide_id = $row->id;
    $member->is_federal = 1;


    $years = explode('-', $row->lifespan);

    //get birth year
    if ($startYear = trim($years[0]))
    {
      $startDate = $startYear . '-00-00';
    }

    $member->start_date = $startDate;


    //set death date if exists
    if ((count($years) > 1) && (int) $years[1])
    {
      $member->end_date = trim($years[1]) . '-00-00';
    }
    
    
    //get info from profile page
    $this->getProfileData($member);
    
    //get info from govtrack
    $this->getGovtrackData($member);
        
    //get info from sunlight
    $this->getSunlightData($member);
    
    //get info from PVS API
    $this->getPvsData($member);
    
    
    return $member;
  }



  public function getProfileData($member)
  {
    //generate URL for member's profile
    $url = $this->_profileUrlBase . $member->bioguide_id;
    $this->_references['bioguide']->source = $url;
    $this->_references['bioguide']->name = 'Congressional Biographical Directory';


    if (!$this->browser->get($url)->responseIsError())
    {    
    
      $this->printDebug("Fetched member's profile page");
      $this->_bioPageText = $text = LsString::newlinesToSpaces($this->browser->getResponseText());


      //get bio
      if (preg_match('/, <\/FONT>([^<]+)<\/(TD|P)>/', $text, $bio))
      {
        $bio = preg_replace('/\n/', ' ', $bio[1]);
        $bio = ucfirst(trim(preg_replace('/\s{2,}/', ' ', $bio)));
        $bio = LsHtml::replaceEntities($bio);
        $member->summary = $bio;
        $this->printDebug("Bio: " . $bio);
        if (preg_match('/\b(a(\s+\p{L}+){2,8})\;/isu',$bio,$match))
        {
           $blurb = 'US ' . preg_replace('/a\s+/isu','',$match[1]);
           $member->blurb = $blurb;
           $this->printDebug("Blurb: " . $blurb);
        }
        
      }


      //get senate term, if any
      if (preg_match('/Service:<\/B><\/FONT>([^<]+)<BR>/', $text, $term))
      {
        $terms = preg_split('/,;/', $term[1]);
        
        foreach ($terms as $term)
        {
          if (!$term = trim($term))
          {
            continue;
          }

          //create relationship
          $rel = new Relationship;
          $rel->Entity1 = $member;
          $rel->entity2_id = $this->_senateEntityId;
          $rel->setCategory('Membership');
          $rel->description1 = 'Senator';           
          //break term into start and end
          $years = explode('-', $term);
          $start = trim($years[0]);
          $rel->start_date = $start . '-00-00';

          $this->printDebug("Senate term start: " . $start);

          
          if (count($years) > 1 && trim($years[1]))
          {
            $end = trim($years[1]);
            $rel->end_date = $end . '-00-00';
            
            $this->printDebug("Senate term end: " . $end);
          }

          
          $this->_senateRelationships[] = $rel;

          $this->printDebug("Created relationship to US Senate");
        }
      }

        
      //get house terms
      preg_match_all('/\((\w+\s+\d{1,2},\s+\d{4})-(present|(\w+\s+\d{1,2},\s+\d{4}))\)/ismU', $text, $matches, PREG_SET_ORDER);
      
      foreach ($matches as $match)
      {
        if ($time = strtotime($match[1]))
        {
          //create relationship
          $rel = new Relationship;
          $rel->Entity1 = $member;
          $rel->entity2_id = $this->_houseEntityId;
          $rel->setCategory('Membership');
          $rel->start_date = date('Y-m-d', $time);
          $rel->description1 = 'Representative';
          $this->printDebug("Created relationship to US House of Reps");
          $this->printDebug("House term start: " . $rel->start_date);


          if ($match[2] != 'present' && $time = strtotime($match[2]))
          {
            $rel->end_date = date('Y-m-d', $time);
            
            $this->printDebug("House term end: " . $rel->end_date);
          }
          
          $this->_houseRelationships[] = $rel;
        }
      }
      

      //get photo url & name
      if (preg_match('/bioguide\/photo\/[A-Z]\/([^"]+)/', $text, $photo))
      {
        if ($photoUrl = $photo[0])
        {
          $this->_photoUrl = 'http://bioguide.congress.gov/' . $photoUrl;
          $photoName = $photo[1];

          $this->printDebug("Photo URL: " . $this->_photoUrl);
          

          //get photo credit
          if (preg_match('/<I>([^<]+)<\/photo\-credit>/', $text, $credit))
          {
            $credit = trim($credit[1]);
            
            $this->printDebug("Photo credit: " . $credit);
          }
          else
          {
            $credit = null;
          }


          if ($fileName = ImageTable::createFiles($this->_photoUrl, $photoName))
          {
            //insert image record
            $image = new Image;
            $image->filename = $fileName;
            $image->title = 'Congress Photo';
            $image->caption = $credit ? $credit : 'From the Biographical Directory of the United States Congress';
            $image->is_featured = true;
            $image->is_free = true;
            $image->url = $this->_photoUrl;
          
            //save for later
            $this->_image = $image;



            $this->printDebug("Imported image: " . $image->filename);
          }        
        }
      }
    }
    else
    {
      //Error response (eg. 404, 500, etc)
      throw new Exception("Couldn't get " . $url);
    }
  }

  protected function matchInDatabase($member)
  {
    $match = LsDoctrineQuery::create()
      ->from('Entity e')
      ->leftJoin('e.PoliticalCandidate pc')
      ->where('(pc.house_fec_id = ? and pc.house_fec_id is not null and pc.house_fec_id <> ?) or (pc.senate_fec_id = ? and pc.senate_fec_id is not null and pc.senate_fec_id <> ?) or (pc.pres_fec_id is not null and pc.pres_fec_id = ? and pc.pres_fec_id <> ?)', array($member->house_fec_id,"",$member->senate_fec_id,"", $member->pres_fec_id,""))
      ->fetchOne();
    
    if (!$match)
    {
      $matches = EntityTable::getByExtensionQuery('Person')
      ->addWhere('person.name_last = ? AND person.name_first LIKE CONCAT(?, \'%\')', array($member->name_last, substr($member->name_first, 0, 1)))
      ->execute();

      foreach ($matches as $m)
      {
        if (PersonTable::areSame($member, $m))
        {
          $match = $m;
          break;
        }    
      }
    }
    if ($match)
    {
      //merge entities
      $this->printDebug("\n\n\t\t\tMERGING MERGING\n\n");
      $this->printDebug("Member found in database with name " . $match->name . " and entity ID " . $match->id);
      $merged_member = EntityTable::mergeAll($match, $member);
      //reassign relationships created during import
  
      $relVarNames = array('senate', 'house', 'school', 'committee');
      
      foreach ($relVarNames as $relVarName)
      {
        $fullRelVarName = '_' . $relVarName . 'Relationships';
        
        foreach ($this->$fullRelVarName as $rel)
        {
          $rel->Entity1 = $merged_member;
        }
      }
      foreach($this->_officeStafferRelationships as $rel)
      {
        $rel->Entity2 = $merged_member;
      } 
      return $merged_member;
    }
    return $member;
  }  


  static function parseBioguideName($str)
  {
    $entity = new Entity;
    $entity->addExtension('Person');

    //extract nickname
    if (preg_match('/\(([^(]+)\)/', $str, $nick))
    {
      $entity->name_nick = $nick[1];
      $str = preg_replace('/\(.*\)/U', '', $str);
    }

    $str = preg_replace('/\s{2,}/', ' ', $str);
    $str = str_replace('.', '', $str);
    $parts = explode(',', trim($str));

    if (count($parts) > 1)
    {
      $entity->name_last = LsLanguage::nameize(mb_strtolower(trim($parts[0]), mb_detect_encoding(trim($parts[0]))));
      $other = explode(' ', trim($parts[1]));
      $entity->name_first = trim($other[0]);

      if (count($other) > 1)
      {
        $middles = array_slice($other, 1);
        $middle = trim(implode($middles, ' '));
        $entity->name_middle = $middle;
      }

      if (count($parts) > 2)
      {
        $suffix = trim($parts[2]);
        $entity->name_suffix = $suffix;
      }
    }
    else
    {
      return null;
    }
    
    return $entity;    
  }


  public function getGovtrackData($member)
  {
    $modified = array();
    
  
    if (!$member->bioguide_id)
    {
      return null;
    }
  

    $gp = Doctrine::getTable('GovtrackPerson')->findOneByBioguideId($member->bioguide_id);
    
    if (!$gp)
    {
      return null;
    }
    
    //get birthday from govtrack
    if ($startDate = $gp->start_date)
    {
      $member->start_date = $startDate;
      $modified[] = 'start_date';
    }


    //get gender from govtrack
    switch ($gp->gender)
    {
      case 'F':
        $member->gender_id = GenderTable::FEMALE;
        $modified[] = 'gender_id';
        break;
        
      case 'M':
        $member->gender_id = GenderTable::MALE;
        $modified[] = 'gender_id';
        break;        
    }


    //get website from govtrack
    if ($url = $gp->url)
    {
      $member->website = $url;
      $modified[] = 'website';
    }


    //get govtrack_id from govtrack
    $member->govtrack_id = $gp->govtrack_id;
    $modified[] = 'govtrack_id';
    
    $this->printDebug("Set govtrack_id: " . $member->govtrack_id);
    
    
    //get opensecrets_id from govtrack
    if ($os_id = $gp->os_id)
    {
      $member->crp_id = $os_id;    
      $modified[] = 'crp_id';
      
      $this->printDebug("Set crp_id: " . $member->crp_id);
    }


    //get state and district
    if ($gp->State->name)
    {
      $state = $gp->State;
      
      if ($lsState = Doctrine::getTable('AddressState')->findOneByName($state->name))
      {
        $this->printDebug('Added representative state: ' . $state->name);
      
      
        if ($federalDistrict = $gp->district)
        {
          $district = LsQuery::getByModelAndFieldsQuery('PoliticalDistrict', array(
            'state_id' => $lsState->id,
            'federal_district' => $federalDistrict
          ))->fetchOne();
          
          if (!$district)
          {
            $district = new PoliticalDistrict;
            $district->State = $lsState;
            $district->federal_district = $federalDistrict;
            $district->save();
          }
          
          $member->ElectedDistrict[] = $district;

          $this->printDebug('Added representative district: ' . $federalDistrict);
        }
      }
    }

    
    //create govtrack reference
    $ref = new Reference;
    $ref->source = $this->_govtrackUrlBase . $member->govtrack_id;
    $ref->name = 'GovTrack.us';
    $ref->addFields($modified);
      
    $this->_references['govtrack'] = $ref;
  }


  //updates congressional committee assignments from Project Vote Smart data -- not currently used, use parse:govtrack-congress-committees task instead
  public function updatePvsCommittees($member)
  {
    /*if (!$member->pvs_id)
    {
      return;
    }


    //get pvs bio data
    $v = new VoteSmart;

    if (!$result = $v->query('CandidateBio.getBio', array('candidateId' => $member->pvs_id)))
    {
      $this->printDebug("Couldn't get PVS committees for member ID " . $member->id);
      return;
    }
    var_dump($result);*/
    
    $gp = Doctrine::getTable('GovtrackPerson')->findOneByBioguideId($member->bioguide_id);
    
    if (!$gp)
    {
      return null;
    }

    $this->printDebug("Updating committees from Project Vote Smart data...");


    //get names of existing committees and subcommittees
    $existingCommittees = array();
    $existingSubcommittees = array();

    if ($member->id)
    {
      //committees always have house or senate as parent
      $q = LsDoctrineQuery::create()
        ->from('Relationship r')
        ->leftJoin('r.Entity2 e')
        ->where('r.entity1_id = ?', $member->id)
        ->andWhereIn('e.parent_id', array($this->_houseEntityId, $this->_senateEntityId));
        
      foreach ($q->fetchArray() as $row)
      {
        $existingCommittees[] = $row['Entity2']['name'];
      }


      /*//subcommittees always have house or senate as grandparent, and names end in 'Subcommittee'
      $q = LsDoctrineQuery::create()
        ->from('Relationship r')
        ->leftJoin('r.Entity2 e')
        ->leftJoin('e.Parent p')
        ->where('r.entity1_id = ?', $member->id)
        ->andWhere('e.name LIKE ?', '% Subcommittee')
        ->andWhereIn('p.parent_id', array($this->_houseEntityId, $this->_senateEntityId));
        
      foreach ($q->fetchArray() as $row)
      {
        $existingCommittees[] = $row['Entity2']['name'];
      }*/
    }


    //get current committees and subcommittees
    $currentCommittees = array();
    $currentCommitteeRoles = array();
    $currentSubcommittees = array();
    $currentSubcommitteeRoles = array();
    $currentSubcommitteeParents = array();



    $q = LsDoctrineQuery::create()
      ->from('GovtrackPersonCommittee gpc')
      ->leftJoin('gpc.Committee gc')
      ->where('gpc.person_id = ? AND gpc.subcommittee_id IS NULL', $gp->govtrack_id);

    foreach ($q->fetchArray() as $row)
    {
      //standardize name
      $name = $row['Committee']['name'];
      $name = strstr($name, 'Committee') ? $name : $name . ' Committee';
      $name = html_entity_decode($name);

      $currentCommittees[] = $name;
      $currentCommitteeRoles[$name] = $row['role'];
    }


    //create relationships to new committees
    $newCommittees = array_diff($currentCommittees, $existingCommittees);
    
    foreach ($newCommittees as $name)
    {
      //see if committee already exists
      if (!$cmte = @$this->_committees[$name])
      {
        $q = LsDoctrineQuery::create()
          ->from('Entity e')
          ->where('e.name = ?', $name);
          
        //create committee if needed
        if (!$cmte = $q->fetchOne())
        {
          $cmte = new Entity;
          $cmte->addExtension('Org');
          $cmte->addExtension('GovernmentBody');
          $cmte->name = $name;
          if (stripos($cmte->name, 'House') === 0)
          {
            $cmte->parent_id = $this->_houseEntityId;
          }
          else if (stripos($cmte->name, 'Senate') === 0)
          {
            $cmte->parent_id = $this->_senateEntityId;
          }
          $cmte->save();
          
          $this->printDebug("Created new committee: " . $name);
          
          $this->_committees[$name] = $cmte;
        }
      }
      
      //create relationship
      $rel = new Relationship;
      $rel->Entity1 = $member;
      $rel->Entity2 = $cmte;
      $rel->setCategory('Membership');

      if ($role = $currentCommitteeRoles[$name])
      {
        $rel->setCategory('Position');
        $rel->description1 = $role;
      }
                 
      $this->_committeeRelationships[] = $rel;
      
      $this->printDebug('Added ' . ($role ? 'membership' : 'position') . ' in ' . $name);
    }


    //end relationships to old committees
    $oldCommittees = array_diff($existingCommittees, $currentCommittees);
    foreach ($oldCommittees as $name)
    {
      $cmte = Doctrine::getTable('Entity')->findOneByName($name);
      
      $q = LsDoctrineQuery::create()
        ->from('Relationship r')
        ->where('r.entity1_id = ?', $member->id)
        ->andWhere('r.entity2_id = ?', $cmte->id)
        ->andWhere('r.end_date IS NULL');
        
      foreach ($q->execute() as $rel)
      {
        $rel->is_current = false;
        $rel->end_date = ($this->_sessionStartYear - 1) . '-00-00';
        $rel->save();
        
        $this->printDebug("Ended relationship in " . $name);
      }    
    }


    //get existing subcommittees
    $existingSubcommittees = array();
    $q = LsDoctrineQuery::create()
      ->from('Relationship r')
      ->leftJoin('r.Entity2 e')
      ->leftJoin('e.Parent p')
      ->where('r.entity1_id = ?', $member->id)
      ->andWhere('e.name LIKE ?', '% Subcommittee')
      ->andWhereIn('p.parent_id', array($this->_houseEntityId, $this->_senateEntityId));
      
    foreach ($q->fetchArray() as $row)
    {
      $existingCommittees[] = $row['Entity2']['name'];
    }


    //get current subcommittees
    $currentSubcommittees = array();
    $currentSubcommitteeRoles = array();
    $currentSubcommitteeParents = array();
    $q = LsDoctrineQuery::create()
      ->from('GovtrackPersonCommittee gpc')
      ->leftJoin('gpc.Committee gc')
      ->leftJoin('gpc.Subcommittee gs')
      ->where('gpc.person_id = ? AND gs.name IS NOT NULL', $gp->govtrack_id);

    foreach ($q->fetchArray() as $row)
    {
      //standardize name
      $name = $row['Subcommittee']['name'];
      $name = strstr($name, 'Subcommittee') ? $name : $name . ' Subcommittee';
      $name = html_entity_decode($name);

      //standardize parent name
      $parentName = $row['Committee']['name'];
      $parentName = strstr($parentName, 'Committee') ? $parentName : $parentName . ' Committee';
      $parentName = html_entity_decode($parentName);

      $currentSubcommittees[] = $name;
      $currentSubcommitteeRoles[$name] = $row['role'];
      $currentSubcommitteeParents[$name] = $parentName;
    }
    

    //create relationships to new subcommittees
    $newSubcommittees = array_diff($currentSubcommittees, $existingSubcommittees);
    
    foreach ($newSubcommittees as $name)
    {
      //see if subcommittee already exists
      if (!$cmte = @$this->_committees[$name])
      {
        $q = LsDoctrineQuery::create()
          ->from('Entity e')
          ->where('e.name = ?', $name);
          
        //create subcommittee if needed
        if (!$cmte = $q->fetchOne())
        {
          $cmte = new Entity;
          $cmte->addExtension('Org');
          $cmte->addExtension('GovernmentBody');
          $cmte->name = $name;

          //get subcommittee's parent
          $parentName = $currentSubcommitteeParents[$name];
          if (!$parent = @$this->_committees[$parentName])
          {
            if (!$parent = Doctrine::getTable('Entity')->findOneByName($parentName))
            {
              $parent = new Entity;
              $parent->addExtension('Org');
              $parent->addExtension('GovernmentBody');
              $parent->name = $parentName;
              $parent->save();
              
              $this->printDebug("Created commmittee: " . $parentName);
            }
          }
          $cmte->parent_id = $parent->id;
          $cmte->save();
          
          $this->printDebug("Created committee: " . $name);
          
          $this->_committees[$name] = $cmte;
        }
      }
      
      //create relationship
      $rel = new Relationship;
      $rel->Entity1 = $member;
      $rel->Entity2 = $cmte;
      $rel->setCategory('Membership');

      if ($role = $currentSubcommitteeRoles[$name])
      {
        $rel->setCategory('Position');;
        $rel->description1 = $role;
      }
                 
      $this->_committeeRelationships[] = $rel;
      
      $this->printDebug('Added ' . ($role ? 'membership' : 'position') . ' in ' . $name);
    }


    //end relationships to old subcommittees
    $oldSubcommittees = array_diff($existingSubcommittees, $currentSubcommittees);
    foreach ($oldSubcommittees as $name)
    {
      $cmte = Doctrine::getTable('Entity')->findOneByName($name);
      
      $q = LsDoctrineQuery::create()
        ->from('Relationship r')
        ->where('r.entity1_id = ?', $member->id)
        ->andWhere('r.entity2_id = ?', $cmte->id)
        ->andWhere('r.end_date IS NULL');
        
      foreach ($q->execute() as $rel)
      {
        $rel->is_current = false;
        $rel->end_date = ($this->_sessionStartYear - 1) . '-00-00';
        $rel->save();
        
        $this->printDebug("Ended relationship with " . $name);
      }    
    }  
  }



  //use Sunlight Labs API to get a bunch of 3rd party IDs
  public function getSunlightData($member)
  {
    $url = $this->_sunlightUrlBase . $member->bioguide_id;
    echo $url . "\n";

    if ($this->browser->get($url)->responseIsError())
    {
      //Error response (eg. 404, 500, etc)
      $this->printDebug("Couldn't get " . $url);
      return null;
    }
    
    $xml = $this->browser->getResponseXml();

    
    if (!$legislators = $xml->xpath('/response/legislator'))
    {
      return;
    }

    $legislator = $legislators[0];
    
    //set VoteSmart ID
    if (!$member->pvs_id && $pvsId = $legislator->votesmart_id)
    {
      $member->pvs_id = $pvsId;
      $this->printDebug("Set pvs_id: " . $pvsId);
    }
    
    
    //set CRP ID
    if (!$member->crp_id && $crpId = $legislator->crp_id)
    {
      $member->crp_id = $crpId;
      $this->printDebug("Set crp_id: " . $crpId);
    }
    
    
    //set FEC ID
    if ($fecId = $legislator->fec_id)
    {
      $char = substr($fecId, 0, 1);
      
      switch ($char)
      {
        case 'H':
          if ($member->house_fec_id != $fecId)
          {
            $member->house_fec_id = $fecId;
            $this->printDebug("Set house_fec_id: " . $fecId);
          }
          break;
        case 'S':
          if ($member->senate_fec_id != $fecId)
          {
            $member->senate_fec_id = $fecId;
            $this->printDebug("Set senate_fec_id: " . $fecId);
          }
          break;
        case 'P':
          if ($member->pres_fec_id != $fecId)
          {
            $member->pres_fec_id = $fecId;
            $this->printDebug("Set pres_fec_id: " . $fecId);    
          }
          break;
      }
    }
  }



  public function getWatchdogData($member)
  {
    //get pvs id
    $wp = Doctrine::getTable('WatchdogPerson')->findOneByGovtrackId($member->govtrack_id);
    
    if (!$wp)
    {
      return;
    }
        
    
    //get watchdog_id and pvs_id
    $member->watchdog_id = $wp->watchdog_id;
    $member->pvs_id = $wp->pvs_id;
    $modified = array('watchdog_id', 'pvs_id');

    $this->printDebug("Set pvs_id: " . $member->pvs_id);  
    $this->printDebug("Set watchdog_id: " . $member->watchdog_id);  


    //get FEC IDs
    foreach ($wp->Fec as $fec)
    {
      $fecId = $fec->fec_id;
      $char = substr($fecId, 0, 1);
      
      switch ($char)
      {
        case 'H':
          $member->house_fec_id = $fecId;
          $modified = array('house_fec_id');
          break;
        case 'S':
          $member->senate_fec_id = $fecId;
          $modified = array('senate_fec_id');
          break;
        case 'P':
          $member->pres_fec_id = $fecId;
          $modified = array('pres_fec_id');
          break;
      }
      
      $this->printDebug("Set " . $modified[0] . ": " . $fecId);
    }
    

    //create reference for watchdog
    $ref = new Reference;
    $ref->source = $this->_watchdogUrlBase . 'p/' . $member->watchdog_id;
    $ref->name = 'watchdog.net';
    $ref->addFields($modified);

    $this->_references['watchdog'] = $ref;
  }


  public function getPvsData($member)
  {
    if (!$member->pvs_id)
    {
      return;
    }


    $modified = array();

    //get pvs bio data
    $v = new VoteSmart;

    if (!$result = $v->query('CandidateBio.getBio', array('candidateId' => $member->pvs_id)))
    {
      $this->printDebug("Couldn't get VoteSmart candidate bio for member ID " . $member->id);
    }
    else
    {
      //get birthplace
      if ($birthplace = $result->candidate->birthPlace)
      {
        $member->birthplace = $birthplace;
        $modified[] = 'birthplace';
        
        $this->printDebug("Set birthplace to: " . $member->birthplace);
      }
        
        
      //get education
      if ($education = $result->candidate->education)
      {
        //parse education
        $educationLines = explode("\n", $education);
        
        $schools = array();
        
        foreach ($educationLines as $educationLine)
        {
          $educationLine = str_replace('.', '', trim($educationLine));
          
          $educationParts = explode(', ', $educationLine);
          $numParts = count($educationParts);
          
          if (!preg_match('/^\d+$/', $educationParts[$numParts-1]))
          {
            continue;
          }
          
          $schoolYear = null;
          $schoolName = null;
          $schoolDegree = null;
          $schoolField = null;
                  
          switch ($numParts)
          {
            case 4:
              $schoolYear = trim($educationParts[3]);
              $schoolName = trim($educationParts[2]);
              $schoolField = trim($educationParts[1]);
              $schoolDegree = trim($educationParts[0]);
              break;
              
            case 3:
              $schoolYear = trim($educationParts[2]);
              $schoolName = trim($educationParts[1]);
              $schoolDegree = trim($educationParts[0]);
              break;
              
            default:
              continue 2;
              break;
          }
            
         
          //look for existing school
          $q = LsDoctrineQuery::create()
                ->from('Entity e')
                ->leftJoin('e.Alias a')
                ->leftJoin('e.ExtensionRecord er')
                ->leftJoin('er.Definition ed on er.definition_id = ed.id')
                ->where('a.name = ? and ed.name = ?',array($schoolName,'School'));
          $school = $q->fetchOne();
          if (isset($schools[$schoolName]))
          {
            $school = $schools[$schoolName];
          }
          elseif (!$school)
          {
            $school = new Entity;
            $school->addExtension('Org');
            $school->addExtension('School');
            $school->name = $schoolName;
            $schools[$schoolName] = $school;
          }
          
          //look for existing school relationship
          $q= LsDoctrineQuery::create()
                ->from('Relationship r')
                ->where('r.entity1_id = ? and r.entity2_id = ? and r.category_id = ?',array($member->id,$school->id,RelationshipTable::EDUCATION_CATEGORY));
          
          if ($q->fetchOne())
          {
            continue;
          }
          //create relationship
          $rel = new Relationship;
          $rel->Entity1 = $member;
          $rel->Entity2 = $school;
          $rel->setCategory('Education');
          $rel->end_date = $schoolYear . '-00-00';        
      
          //look for degree
          if ($degree = DegreeTable::getByText($schoolDegree))
          {
            $rel->Degree = $degree;
          }
    
          if ($schoolField)
          {
            $rel->field = $schoolField;
          }
    
                    
          $this->_schoolRelationships[] = $rel;
          
          $this->printDebug("Added relationship to school: " . $schoolName);
        }      
      }
    }
    
    //get office info
    $v = new VoteSmart;

    if (!$result = $v->query('Address.getOffice', array('candidateId' => $member->pvs_id)))
    {
      $this->printDebug("Couldn't get VoteSmart office info for member ID " . $member->id);
    }
    else
    {
      foreach ($result->office as $office)
      {
        //save address
        if ($office->address && (string) $office->address->state != 'NA')
        {
          $address = new Address;

          //set street parts
          $streetParts = explode("\n", (string) $office->address->street);
          $countTo = count($streetParts) < 4 ? count($streetParts) : 3;
  
          for ($n = 1; $n <= $countTo; $n++)
          {
            $field = 'street' . $n;
            $address->$field = $streetParts[$n-1];
          }

          //set other parts
          $address->city = LsString::emptyToNull((string) $office->address->city);
          $address->State = AddressStateTable::retrieveByText(LsString::emptyToNull((string) $office->address->state));
          $address->postal = LsString::emptyToNull((string) $office->address->postal);
          $address->country_id = 1;
          $address->category_id = AddressTable::OFFICE_CATEGORY;
          $this->_offices[] = $address;

        }
                
        //if there's a contact, add as a staffer
        /*if ($contactName = (string) $office->notes->contactName)
        {
          //create person from name
          $staffer = PersonTable::parseFlatName($contactName);
          
          if (!$staffer->name_last)
          {
            continue;
          }
  
          $this->_staffers[] = $staffer;          
          
          $this->printDebug("Added STAFFER: " . $staffer->name_last);          
  
          //create position relationship
          $stafferRel = new Relationship;
          $stafferRel->Entity1 = $staffer;
          $stafferRel->Entity2 = $member;
          $stafferRel->setCategory('Position');
                    
          if ($contactTitle = (string) $office->notes->contactTitle)
          {
            $stafferRel->description1 = $contactTitle;
          }      
          
          $this->_officeStafferRelationships[] = $stafferRel;
  
          $this->printDebug("Created relationship between staffer and member");          
        }*/
      }
    }    

    //create reference
    $ref = new Reference;
    $ref->source = $this->_pvsUrlBase . $member->pvs_id;
    $ref->name = 'Project Vote Smart';
    $ref->addFields($modified);
    
    $this->_references['pvs'] = $ref;
  }


  public function updateBio($member)
  {
    $url = $this->_profileUrlBase . $member->bioguide_id;

    if ($this->browser->get($url)->responseIsError())
    {        
      //Error response (eg. 404, 500, etc)
      throw new Exception("Couldn't get " . $url);
    }

    $this->_bioPageText = $text = LsString::newlinesToSpaces($this->browser->getResponseText());

    //get bio
    if (preg_match('/, <\/FONT>([^<]+)<\/(TD|P)>/', $text, $bio))
    {
      $bio = preg_replace('/\n/', ' ', $bio[1]);
      $bio = ucfirst(trim(preg_replace('/\s{2,}/', ' ', $bio)));
      $bio = LsHtml::replaceEntities($bio);
      $member->summary = $bio;
      $this->printDebug("Bio: " . $bio);
      if (preg_match('/\b(a(\s+\p{L}+){2,8})\;/isu',$bio,$match))
      {
         $blurb = 'US ' . preg_replace('/a\s+/isu','',$match[1]);
         $member->blurb = $blurb;
         $this->printDebug("Blurb: " . $blurb);
      }        
    }
    else
    {
      $this->printDebug("Couldn't find member bio on " . $url);
    }
  }
  
  public function addListMember($member)
  {
    $q = LsDoctrineQuery::create()
      ->from('LsListEntity le')
      ->where('le.list_id = ? AND le.entity_id = ?', array($this->_sessionListId, $member->id));
    if (!$q->count())
    {
      $le = new LsListEntity;
      $le->entity_id = $member->id;
      $le->list_id = $this->_sessionListId;
      $le->save();
    }
  }
  
  
  public function updateDiscontinuingMemberRelationships($session)
  {
    $this->printDebug("\n\nUpdating relationships for discontinuing members of session " . ($session - 1) . "\n");

    try
    {
      $this->db->beginTransaction();


      //get current session members for reference
      $this->loadExistingSessionMembers($session);


      //get previous session members
      $q = LsQuery::getByModelAndFieldsQuery('ObjectTag', array(
        'object_model' => 'Entity'
      ))->select('objecttag.object_id');
      
      $results = $q->leftJoin('objecttag.Tag t')
        ->addWhere('t.triple_namespace = ? AND t.triple_predicate = ? AND t.triple_value = ?', array('congress', 'session', $session - 1))
        ->fetchArray();
  
  
      foreach ($results as $ary)
      {
        
        //if member not in existing session, end their previous session relationship
        if (!in_array($ary['object_id'], $this->_existingSessionMemberIds))
        {
          $this->printDebug("Ending relationships for discontinuing member with ID " . $ary['object_id']);
          $q = LsDoctrineQuery::create()
            ->update('Relationship r')
            ->where('r.entity1_id = ?', $ary['object_id'])
            ->andWhere('r.category_id = ?', RelationshipTable::MEMBERSHIP_CATEGORY)
            ->andWhere('r.end_date IS NULL')
            ->andWhereIn('r.entity2_id', array($this->_houseEntityId, $this->_senateEntityId))
            ->set('r.is_current', '?', false)
            ->set('r.end_date', '?', ($this->_sessionStartYear - 1) . '-00-00')
            ->execute();  
        }
      }
      
      if (!$this->testMode)
      {
        $this->db->commit();
      }
    }      
    catch (Exception $e)
    {
      $this->db->rollback();
      throw $e;
    }      
  }
}




class CongressMemberScraperRow
{
  public $id;
  public $name;
  public $lifespan;
  public $type;
  public $party;
  public $state;
  public $termStart;
  public $termEnd;

  public function __construct($row)
  {
    $this->id = $row[1];
    $this->name = trim(LsHtml::replaceEntities($row[2]));
    $this->lifespan = trim(LsHtml::replaceEntities($row[3]));
    $this->type = trim($row[4]);
    $this->party = trim($row[5]);
    $this->state = trim($row[6]);
    list($this->termStart, $this->termEnd) = explode('-', trim($row[7]));
  }
}
