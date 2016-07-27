<?php
/*
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2003-2012 by the Manageentities Development Team.

 https://forge.indepnet.net/projects/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginManageentitiesCri extends CommonDBTM {
   
   function canView() {
      return plugin_manageentities_haveRight("cri_create","r");
   }
   
   function canCreate() {
      return plugin_manageentities_haveRight("cri_create","w");
   }
   
   function showForm($ID) {
      global $DB,$LANG,$CFG_GLPI;

      $config = PluginManageentitiesConfig::getInstance();
      $contract = new Contract();

      echo "<form action=\"".$CFG_GLPI["root_doc"].
         "/plugins/manageentities/front/cri.form.php\" method=\"post\" name=\"formReport\">";

      // Champ caché pour l'identifiant du ticket.
      echo "<input type=\"hidden\" name=\"REPORT_ID\" value=\"$ID\" />";
      echo "<div align=\"center\"><table class='tab_cadre_fixe' border=\"0\" cellspacing=\"2\"
      cellpadding=\"2\" width=\"50%\">";

      /* Information complémentaire déterminant les intervenants si plusieurs. */
      $job = new Ticket();
      $job->getfromDB($ID);
      
      $PluginManageentitiesCriTechnician=new PluginManageentitiesCriTechnician();
      $technician_ID=$PluginManageentitiesCriTechnician->checkIfTechnicianExists($ID);
      $users = $job->getUsers(Ticket::ASSIGN);
      
      echo "<tr class='tab_bg_1' colspan='2'>";
      echo "<td class='center'>";
      echo $LANG['plugin_manageentities']['infoscompreport'][4]." : ";
      
      if ($users) {

         if  (!$technician_ID) {
            foreach ($users as $k => $d) {
               $technician_ID=$PluginManageentitiesCriTechnician->addDefaultTechnician($d["users_id"],$ID);
            }
         }

         $query = "SELECT *
             FROM `glpi_plugin_manageentities_critechnicians`
             WHERE `tickets_id` = '".$ID."' ";
         $result = $DB->query($query);
         while ($data = $DB->fetch_array($result)) {

            echo getusername($data["users_id"])."&nbsp;";
            
            Html::showSimpleForm($CFG_GLPI['root_doc'].'/plugins/manageentities/front/cri.form.php',
                                    'delete_tech',
                                    $LANG['buttons'][6],
                                    array('tech_id' => $data["id"],
                                          'job' => $ID),
                                     $CFG_GLPI["root_doc"]."/pics/puce-delete2.png");
         }
      } else {
         echo "<font color='red'>".$LANG['plugin_manageentities']['infoscompreport'][6]."</font>";
         
      }
      echo "</td>";
      echo "</tr>";
      
      echo "<tr class='tab_bg_1' colspan='2'>";
      echo "<td class='center'>";
      if ($users>0) {
         User::dropdown(array('name' => "users_id",
                              'entity' => $job->fields["entities_id"],
                              'right' => 'all'));
         echo "&nbsp;<input type='submit' name='add_tech' value=\"".
            $LANG['plugin_manageentities']['infoscompreport'][5]."\" class='submit'>";
      }
      echo "</td>";
      echo "</tr>";

      /* Information complémentaire déterminant si sous contrat ou non. */

      $restrict = "`glpi_plugin_manageentities_cridetails`.`entities_id` = '".
         $job->fields['entities_id']."'
                  AND `glpi_plugin_manageentities_cridetails`.`tickets_id` = '".
         $job->fields['id']."'";
      $cridetails = getAllDatasFromTable("glpi_plugin_manageentities_cridetails", $restrict);
      $cridetail = reset($cridetails);

      echo "<tr class='tab_bg_1' colspan='2'>";
      echo "<td class='center'>";
      echo $LANG['plugin_manageentities']['infoscompreport'][0]." : ";

      echo "<select name='CONTRAT'>";
      echo "<option value='0'>".Dropdown::EMPTY_VALUE."</option>";
      $query = "SELECT DISTINCT(`glpi_contracts`.`id`),
                        `glpi_contracts`.`name`,
                        `glpi_contracts`.`num`,
                       `glpi_plugin_manageentities_contracts`.`id` as ID_us,
                       `glpi_plugin_manageentities_contracts`.`is_default` as is_default
          FROM `glpi_contracts`
          LEFT JOIN `glpi_plugin_manageentities_contracts`
               ON (`glpi_plugin_manageentities_contracts`.`contracts_id` = `glpi_contracts`.`id`)
          LEFT JOIN `glpi_plugin_manageentities_contractdays`
               ON (`glpi_plugin_manageentities_contractdays`.`contracts_id` = `glpi_contracts`.`id`)
          LEFT JOIN `glpi_plugin_manageentities_contractstates`
               ON (`glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id`
               = `glpi_plugin_manageentities_contractstates`.`id`)
          WHERE `glpi_plugin_manageentities_contracts`.`entities_id` = '".
               $job->fields["entities_id"]."'
               AND `glpi_plugin_manageentities_contractstates`.`is_active` = 1
          ORDER BY `glpi_contracts`.`name` ";

      $result = $DB->query($query);
      $number = $DB->numrows($result);
      $selected = false;

      if ($number) {
         while ($data=$DB->fetch_array($result)) {

            echo "<option value='".$data["id"]."'";
            if ($cridetail['contracts_id']==$data["id"]){
               echo "selected='selected'";
               $selected = true;
            } else if($data["is_default"]=='1' && !$selected) {
               echo "selected='selected'";
            }
            echo ">".$data["name"]." - ".$data["num"]."</option>";
         }
      }
      echo "</select>";

      if(!empty($cridetail) && $cridetail['contracts_id']!='0'){
         $contract->getFromDB($cridetail['contracts_id']);
         echo '&nbsp;';
         Html::showToolTip($contract->fields['comment'],
            array('link'=>$contract->getLinkURL(),
               'linktarget'=> '_blank'));
      }

      echo "</td>";
      echo "</tr>";

      if($config->fields['useprice']=='1'){
         /* Information complémentaire pour le libellés des activités. */
         echo "<tr class='tab_bg_1' colspan='2'>";
         echo "<td class='center'>";
         echo $LANG['plugin_manageentities'][14]." : ";
         $PluginManageentitiesCriPrice = new PluginManageentitiesCriPrice();
         Dropdown::show('PluginManageentitiesCriType',
            array('name' => 'REPORT_ACTIVITE',
                  'entity' => $job->fields["entities_id"],
                  'used' => $PluginManageentitiesCriPrice->checkTypeByEntity($job->fields["entities_id"])));
         echo "</td>";
         echo "</tr>";
      //configuration do not use price
      } else {
         echo "<input type='hidden' name='REPORT_ACTIVITE' value='noprice' />";
      }


      /*
       * Information complémentaire pour la description globale du CRI.
       * Préremplissage avec les informations des suivis non privés.
       */
      $desc = "";
      $join = "";
      $and = "";
      if($config->fields['useprice']!='1'){
         $join=" LEFT JOIN `glpi_plugin_manageentities_taskcategories`
                        ON (`glpi_plugin_manageentities_taskcategories`.`taskcategories_id` =
                        `glpi_tickettasks`.`taskcategories_id`)";
         $and=" AND `glpi_plugin_manageentities_taskcategories`.`is_usedforcount` = 1";
      }
      if($config->fields['use_publictask']=='1'){
         $query = "SELECT `content`
                   FROM `glpi_tickettasks` $join
                   WHERE `tickets_id` = '".$ID."'
                   AND `is_private` = false $and";
      } else {
         $query = "SELECT `content`
                   FROM `glpi_tickettasks` $join
                   WHERE `tickets_id` = '".$ID."' $and";
      }

      $result = $DB->query($query);
      while ($data = $DB->fetch_array($result)) {
        $desc .= $data["content"]."\n\n";
      }
      $desc = substr($desc, 0, strlen($desc) - 2); // Suppression des retours chariot pour le dernier suivi...
      echo "<tr class='tab_bg_1' colspan='2'>";
      echo "<td class='center'>";
      echo $LANG['plugin_manageentities']['infoscompreport'][2]." :<br />";
      echo "<textarea name=\"REPORT_DESCRIPTION\" cols='100' rows='8'>$desc</textarea>";
      echo "</td>";
      echo "</tr>";

      /* Bouton de génération du rapport. */
      echo "<tr class='tab_bg_1'>";
      echo "<td class='center'>";
      if ($users>0) {
          echo "<input type='submit' name='add_cri' value=\"".
             $LANG['plugin_manageentities']['title'][2]."\" class='submit'>";
      }
      echo "</td>";
      echo "</tr>";
      echo "</table></div>";
      Html::closeForm();
   }

   /**
   * Récupération des données et génération du document. Il sera enregistré suivant le paramétre enregistrement.
   * @param $id_job Identifiant du ticket pour lequel on souhaite un CRI.
   * @param $sous_contrat Détermine si le CRI est sous contrat.
   * @param $libelle_activite Libellé de l'activité des temps passés du CRI.
   * @param $description_cri Description du CRI.
   * @param $enregistrement Détermine si on souhaite l'enregistrer.
   */
   function generatePdf($id_job, $contrat, $libelle_activite, $description_cri, $enregistrement) {
      global $PDF,$DB,$LANG;

      // ajout de la configuration du plugin
      $config = PluginManageentitiesConfig::getInstance();

      $PDF = new PluginManageentitiesCriPDF('P', 'mm', 'A4');
      //$PDF->SetLibellesRapport($LANG['plugin_manageentities']['cri']);

      /* Initialisation du document avec les informations saisies par l'utilisateur. */
      $title = $libelle_activite;
      if($config->fields['useprice']!='1'){
         $libelle_activite = array();
         $title = "";
      }
      //$PDF->SetDescriptionCri(Toolbox::unclean_cross_side_scripting_deep($description_cri));
      $description_cri = Toolbox::unclean_cross_side_scripting_deep($description_cri);
      $PDF->SetDescriptionCri($description_cri);

      $job = new Ticket();
      if ($job->getfromDB($id_job)) {
         /* Récupération des informations du ticket et initialisation du rapport. */
         $PDF->SetDemandeAssociee($id_job); // Demande / ticket associée au rapport.

         $intervenants="";
         $query = "SELECT * FROM `glpi_plugin_manageentities_critechnicians`
                  WHERE `tickets_id`='".$id_job."' ORDER BY `id` ";
         $result = $DB->query($query);
         while ($data = $DB->fetch_array($result)) {
            $intervenants.=getusername($data["users_id"]).", ";
         }
         $intervenants=substr($intervenants, 0, -2);
         $PDF->SetIntervenant($intervenants);

         /* Année et mois de l'intervention (post du ticket). */
         $infos_date = array();
         $infos_date[0] = $job->fields["date"];
         /* Du ... au ... */
         //configuration only public task
         $where = "";
         $join = "";
         $and="";
         if($config->fields['use_publictask'] == '1'){
            $where = " AND `is_private` = false";
         }
         if($config->fields['useprice']!='1'){
            $join=" LEFT JOIN `glpi_plugin_manageentities_taskcategories`
                        ON (`glpi_plugin_manageentities_taskcategories`.`taskcategories_id` =
                        gf.taskcategories_id)";
            $and=" AND `glpi_plugin_manageentities_taskcategories`.`is_usedforcount` = 1";
         }
         $query = "SELECT MAX(max_date) AS max_date, MIN(min_date) AS min_date
              FROM (
                    SELECT MAX(gf.end) AS max_date, MIN(gf.begin) AS min_date
                      FROM glpi_tickettasks gf $join
                     WHERE gf.tickets_id = '$id_job' $where $and
                   ) t";

         $result = $DB->query($query);
         while ($data = $DB->fetch_array($result)) {
            $infos_date[1] = $data["min_date"];
            $infos_date[2] = $data["max_date"];
         }
         $PDF->SetDateIntervention($infos_date);

         /* Information de l'entité active et son contrat. */
         $infos_entite = array();
         $entite = new Entity();
         $entite->getFromDB($job->fields["entities_id"]);
         $infos_entite[0] = $entite;
         $donnees_entite = new EntityData();
         $donnees_entite->getFromDB($job->fields["entities_id"]);
         $infos_entite[1] = $donnees_entite;

         if ($contrat) {
            $contract = new contract;
            $contract->getFromDB($contrat);
            $infos_entite[2] = $contract->fields["num"];
            $sous_contrat = true;
         } else {
            $infos_entite[2] = "";
            $sous_contrat = false;
         }
         $PDF->SetSousContrat($sous_contrat);

         $PDF->SetEntite($infos_entite);

         /* Récupération des suivis du ticket pour la gestion des temps passés. */
         $temps_passes = array();
         //configurration by day
         if($config->fields['hourorday'] == 0) {
            $nbhour = $config->fields["hourbyday"];
         } else {
            //configuration by hour
            $nbhour = 1;
         }

         $query = "SELECT taskcat,
                          date_debut,
                          date_fin,
                          heure_debut,
                          heure_fin,
                          actiontime,
                          tps_passes
              FROM (
                    SELECT gf.id,
                           gf.taskcategories_id AS taskcat,
                           gf.begin AS date_debut,
                           gf.end AS date_fin,
                           gf.begin AS heure_debut,
                           gf.end AS heure_fin,
                           gf.actiontime as actiontime,
                           ((gf.actiontime/3600) / ".
                              $nbhour.") AS tps_passes
                  FROM glpi_tickettasks gf $join
                     WHERE gf.tickets_id = $id_job $where $and
                       UNION
                    SELECT gf2.id,
                           gf2.taskcategories_id AS taskcat,
                           gf2.date AS date_debut,
                           gf2.date AS date_fin,
                           '-' AS heure_debut,
                           '-' AS heure_fin,
                           '-' AS actiontime,
                           ((gf2.actiontime/3600) / ".
                              $nbhour.") AS tps_passes
                    FROM glpi_tickettasks gf2
                     WHERE gf2.tickets_id = $id_job
                       AND gf2.id NOT IN (SELECT DISTINCT id FROM glpi_tickettasks gtp2)
                   ) t
               ORDER BY t.date_debut ASC";
         $result = $DB->query($query);
         $cpt_tps = 0;

         while ($data = $DB->fetch_array($result)) {
            $un_temps_passe = array();
            $un_temps_passe[0] =
            substr($data["date_debut"], 8, 2)."/".
               substr($data["date_debut"], 5, 2)."/".
               substr($data["date_debut"], 0, 4);
            $un_temps_passe[1] =
            ($data["heure_debut"] == "-")?"-":substr($data["heure_debut"], 11, 2).
               ":".substr($data["heure_debut"], 14, 2);
            $un_temps_passe[2] =
            substr($data["date_fin"], 8, 2)."/".
               substr($data["date_fin"], 5, 2)."/".
               substr($data["date_fin"], 0, 4);
            $un_temps_passe[3] =
            ($data["heure_fin"] == "-")?"-":substr($data["heure_fin"], 11, 2).
               ":".substr($data["heure_fin"], 14, 2);
//            $un_temps_passe[4] = round($data["tps_passes"], 2);
            //arrondir au quart
            $un_temps_passe[4] = $PDF->TotalTpsPassesArrondis(round($data["tps_passes"], 2));

            //--> ancienne méthode par rapport é l'alternative qui suit.
            // Alternative si on ne prend pas le realtime mais bien la différence de planif dans le cas ou planif il y a.
            /*   if ($data["heure_fin"] != "-") {
            $date_debut = mktime(substr($data["date_debut"], 11, 2), substr($data["date_debut"], 14, 2),
            substr($data["date_debut"], 17, 2), substr($data["date_debut"], 5, 2),
            substr($data["date_debut"], 8, 2), substr($data["date_debut"], 0, 4));
            $date_fin = mktime(substr($data["date_fin"], 11, 2), substr($data["date_fin"], 14, 2),
            substr($data["date_fin"], 17, 2), substr($data["date_fin"], 5, 2),
            substr($data["date_fin"], 8, 2), substr($data["date_fin"], 0, 4));
            $diff = $date_fin - $date_debut;
            $un_temps_passe[4] = round( ((($diff / 60) / 60) / 8), 2);
            } else {
            $un_temps_passe[4] = round($data["jours_passees"], 2);
            }*/

            $temps_passes[$cpt_tps] = $un_temps_passe;

            if($config->fields['useprice']!='1'){
               $libelle_activite[$cpt_tps]= Dropdown::getDropdownName('glpi_taskcategories',
                                                                        $data['taskcat']);
               
            }

            $cpt_tps++;

         }

         $PDF->SetLibelleActivite($libelle_activite);

         $PDF->SetTempsPasses($temps_passes);

      }

      // On dessine le document.
      $PDF->DrawCri();

      //for insert into table cridetails
      $totaltemps_passes=$PDF->TotalTpsPassesArrondis($_SESSION["glpi_plugin_manageentities_total"]);

      /* Génération du fichier et enregistrement de la liaisons en base. */
      $filename = "CRI - ".$PDF->GetNoCri().".pdf";
      $savepath = GLPI_DOC_DIR."/_uploads/";
      $seepath = GLPI_PLUGIN_DOC_DIR."/manageentities/";
      $savefilepath = $savepath.$filename;
      $seefilepath = $seepath.$filename;
      
      if ($config->fields["backup"] == 1  && $enregistrement) {
        
         $PDF->Output($savefilepath, 'F');
        
         $input=array();
         $input["entities_id"] = $job->fields["entities_id"];
         $input["name"] = addslashes("CRI - ".$PDF->GetNoCri());
         $input["upload_file"]=$filename;
         $input["documentcategories_id"] = $config->fields["documentcategories_id"];
         $input["mime"] = "application/pdf";
         $input["date_mod"]=date("Y-m-d H:i:s");
         $input["users_id"]=Session::getLoginUserID();
         $input["tickets_id"] = $id_job;
        
         $doc = new document;
         $newdoc=$doc->add($input);
        
         $withcontract=0;
         if ($sous_contrat==true)
            $withcontract=1;
        
         $values=array();
         $values["entities_id"] = $job->fields["entities_id"];
         $values["date"] = $infos_date[2];
         $values["documents_id"]=$newdoc;
         $values["plugin_manageentities_critypes_id"] = $title;
         $values["withcontract"] = $withcontract;
         $values["contracts_id"]=$contrat;
         $values["realtime"]=$totaltemps_passes;
         $values["technicians"] = $intervenants;
         $values["tickets_id"] = $id_job;

         $restrict = "`glpi_plugin_manageentities_cridetails`.`entities_id` = '".
            $job->fields['entities_id']."'
                  AND `glpi_plugin_manageentities_cridetails`.`tickets_id` = '".
            $job->fields['id']."'";
         $cridetails = getAllDatasFromTable("glpi_plugin_manageentities_cridetails", $restrict);
         $cridetail = reset($cridetails);

         $PluginManageentitiesCriDetail = new PluginManageentitiesCriDetail();
         if(empty($cridetail)){
            $newID=$PluginManageentitiesCriDetail->add($values);
         } else {
            $values["id"] = $cridetail['id'];
            $PluginManageentitiesCriDetail->update($values);
         }

         echo "<table class='tab_cadre_fixe' border='0' cellspacing='2' cellpadding='2' width='100%'>";
         echo "<tr class='tab_bg_1'><td>";
         echo "<b>".$LANG['plugin_manageentities']['cri'][40]."</b>";
         echo "</td></tr>";
         echo "</table>";
         $this->CleanFiles($seepath);
         echo "<script type='text/javascript' >\n";
         echo "window.opener.location.reload();";
         echo "</script>";
         
      } else {

         //Sauvegarde du PDF dans le fichier
         $PDF->Output($seefilepath);
        
         if ($config->fields["backup"] == 1) {
            echo "<form action='./cri.form.php' method='post'>";
            echo "<div align='center'>";

            echo "<table class='tab_cadre_fixe' border='0' cellspacing='2' cellpadding='2' width='100%'>";
            echo "<tr class='tab_bg_1'><td>";
            echo "<b>".$LANG['plugin_manageentities']['title'][2]."</b>";
            echo "</td></tr>";
            echo "</table>";
            echo "<br><br>";
            echo "<input type='hidden' name='REPORT_ID' value='$id_job' >";
            echo "<input type='hidden' name='REPORT_SOUS_CONTRAT' value='$sous_contrat' >";
            if($config->fields['useprice']=='1'){
               echo "<input type='hidden' name='REPORT_ACTIVITE' value='$libelle_activite' >";
            } else {
               echo "<input type='hidden' name='REPORT_ACTIVITE' value='noprice' />";
            }
            $description_cri = stripcslashes($description_cri);
            $description_cri = str_replace("\\\\", "\\", $description_cri);
            $description_cri = str_replace("\\'", "'", $description_cri);
            $description_cri = str_replace("<br>", " ", $description_cri);

            echo "<textarea style='display:none;' name='REPORT_DESCRIPTION' cols='100' rows='8'>".
               $description_cri."</textarea>";
            echo "<input type='hidden' name='INTERVENANTS' value='$intervenants' >";
            echo "<input type='hidden' name='CONTRAT' value='".$contrat."' >";
            echo "<input type='submit' name='save_cri' value=\"".
               $LANG['plugin_manageentities']['infoscompreport'][3]."\" class='submit' >";
            echo "<br><br>";
            echo "<table class='tab_cadre_fixe' border='0' cellspacing='2' cellpadding='2' width='100%'>";
            echo "<tr class='tab_bg_1'><td>";
            echo "<b>".$LANG['plugin_manageentities']['cri'][39]."</b>";
            echo "</td></tr>";
            echo "</table>";
            echo "</div>";
            Html::closeForm();
         }
         //".GLPI_ROOT . "/plugins/manageentities/front/cri.send.php?file=_plugins/manageentities/
         echo "<IFRAME src='$seefilepath' width='1000' height='1000' scrolling=auto frameborder=1></IFRAME>";
      }
   }

  function CleanFiles($dir) {
      //Efface les fichiers temporaires
      $t=time();
      $h=opendir($dir);
      while($file=readdir($h)) {
          if (substr($file,0,3)=='CRI' and substr($file,-4)=='.pdf') {
            $path=$dir.'/'.$file;
            //if ($t-filemtime($path)>3600)
            @unlink($path);
         }
      }
      closedir($h);
   }
}

?>