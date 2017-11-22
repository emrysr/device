<?php
/*
     Released under the GNU Affero General Public License.
     See COPYRIGHT.txt and LICENSE.txt.

     Device module contributed by Nuno Chaveiro nchaveiro(at)gmail.com 2015
     ---------------------------------------------------------------------
     Sponsored by http://archimetrics.co.uk/
*/

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

class DeviceTemplate
{
    protected $mysqli;
    protected $redis;
    protected $log;

    // Module required constructor, receives parent as reference
    public function __construct(&$parent) {
        $this->mysqli = &$parent->mysqli;
        $this->redis = &$parent->redis;
        $this->log = new EmonLogger(__FILE__);
    }

    public function get_template_list() {
        return $this->load_template_list();
    }

    protected function load_template_list() {
        $list = array();        

        $iti = new RecursiveDirectoryIterator("Modules/device/data");
        foreach(new RecursiveIteratorIterator($iti) as $file){
            if(strpos($file ,".json") !== false){
                $content = json_decode(file_get_contents($file));
                $list[basename($file, ".json")] = $content;
            }
        }
        
        asort($list);
        
        return $list;
    }

    public function get_template($type) {
        $type = preg_replace('/[^\p{L}_\p{N}\s-:]/u','', $type);
        $list = $this->load_template_list();
        return $list[$type];
    }

    public function init_template($userid, $nodeid, $name, $type, $dry_run) {

        $userid = (int) $userid;
        $dry_run = (int) $dry_run;

        $list = $this->load_template_list();
        if (!isset($list[$type])) return array('success'=>false, 'message'=>"Template file not found '".$type."'");
        $template = $list[$type];
        
        $feeds = $template->feeds;
        $inputs = $template->inputs;
        
        $log = "";
        
        // Create feeds
        $log .= $this->create_feeds($userid, $nodeid, $feeds, $dry_run);
        
        // Create inputs
        $log .= $this->create_inputs($userid, $nodeid, $inputs, $dry_run);
        
        // Create inputs processes
        $log .= $this->create_input_processes($userid, $feeds, $inputs, $nodeid, $dry_run);
        
        // Create feeds processes
        $log .= $this->create_feed_processes($userid, $feeds, $inputs, $dry_run);
        
        return array('success'=>true, 'message'=>'Device initialized', 'log'=>$log);
    }

    // Create the feeds
    protected function create_feeds($userid, $nodeid, &$feeds, $dry_run) {
        global $feed_settings;
        
        $log = "feeds:\n";
        
        require_once "Modules/feed/feed_model.php";
        $feed = new Feed($this->mysqli, $this->redis, $feed_settings);
        
        foreach($feeds as $f) {
            // Create each feed
            $name = $f->name;
            if (property_exists($f, "tag")) {
                $tag = $f->tag;
            } else {
                $tag = $nodeid;
            }
            $datatype = constant($f->type); // DataType::
            $engine = constant($f->engine); // Engine::
            $options = new stdClass();
            if (property_exists($f, "interval")) {
                $options->interval = $f->interval;
            }
            
            $feedid = $feed->exists_tag_name($userid, $tag, $name);
            
            if ($feedid == false) {
                if (!$dry_run) {
                    $this->log->info("create_feeds() userid=$userid tag=$tag name=$name datatype=$datatype engine=$engine");
                    $result = $feed->create($userid, $tag, $name, $datatype, $engine, $options);
                    if($result["success"] !== true) {
                        $log .= "-- ERROR $tag:$name\n";
                    } else {
                        $feedid = $result["feedid"]; // Assign the created feed id to the feeds array
                        $log .= "-- CREATE $tag:$name\n";
                    }
                } else {
                    $log .= "-- CREATE $tag:$name\n";
                }
            } else {
                $log .= "-- EXISTS $tag:$name\n";
            }
            
            $f->feedid = $feedid;
        }
        return $log."\n";
    }

    // Create the inputs
    protected function create_inputs($userid, $nodeid, &$inputs, $dry_run) {
        require_once "Modules/input/input_model.php";
        $input = new Input($this->mysqli, $this->redis, null);
        
        $log = "inputs:\n";

        foreach($inputs as $i) {
            // Create each input
            $name = $i->name;
            $description = $i->description;
            if(property_exists($i, "node")) {
                $node = $i->node;
            } else {
                $node = $nodeid;
            }
            
            $inputid = $input->exists_nodeid_name($userid, $node, $name);
            
            if ($inputid == false) {
                if (!$dry_run) {
                    $this->log->info("create_inputs() userid=$userid nodeid=$node name=$name description=$description");
                    $inputid = $input->create_input($userid, $node, $name);
                    if(!$input->exists($inputid)) {
                        $log .= "-- ERROR $node:$name\n";
                    } else {
                        $log .= "-- CREATE $node:$name\n";
                    }
                    $input->set_fields($inputid, '{"description":"'.$description.'"}');
                } else {
                    $log .= "-- CREATE $node:$name\n";
                }
            } else {
                $log .= "-- EXISTS $node:$name\n";
            }
            $i->inputid = $inputid; // Assign the created input id to the inputs array
        }
        return $log."\n";
    }

    // Create the inputs process lists
    protected function create_input_processes($userid, $feeds, $inputs, $nodeid, $dryrun) {
        global $user, $feed_settings;
        
        require_once "Modules/feed/feed_model.php";
        $feed = new Feed($this->mysqli, $this->redis, $feed_settings);
        
        require_once "Modules/input/input_model.php";
        $input = new Input($this->mysqli, $this->redis, $feed);
        
        require_once "Modules/process/process_model.php";
        $process = new Process($this->mysqli, $input, $feed, $user->get_timezone($userid));
        $process_list = $process->get_process_list(); // emoncms supported processes
        
        $log = "input processes:\n";
        
        foreach($inputs as $i) {
            // for each input
            if (isset($i->processList) || isset($i->processlist)) {
        		$processes = isset($i->processList) ? $i->processList : $i->processlist;
                $inputid = $i->inputid;
                $result = $this->convert_processes($feeds, $inputs, $processes, $process_list);
                if (isset($result["success"])) {
                    $log .= "-- SET ERROR ".$nodeid.":".$i->name." ".$result["message"];
                }

                $processes = implode(",", $result);
                if ($processes != "") {
                    if (!$dryrun) {
                        $this->log->info("create_inputs_processes() calling input->set_processlist inputid=$inputid processes=$processes");
                        $input->set_processlist($userid, $inputid, $processes, $process_list);
                    }
                    $log .= "-- SET ".$nodeid.":".$i->name."\n";
                    $log .= $this->log_processlist($processes,$input,$feed,$process_list);
                }
            }
        }

        return $log."\n";
    }

    protected function create_feed_processes($userid, $feeds, $inputs, $dryrun) {
        global $user, $feed_settings;
        
        require_once "Modules/feed/feed_model.php";
        $feed = new Feed($this->mysqli, $this->redis, $feed_settings);
        
        require_once "Modules/input/input_model.php";
        $input = new Input($this->mysqli, $this->redis, $feed);
        
        require_once "Modules/process/process_model.php";
        $process = new Process($this->mysqli, $input, $feed, $user->get_timezone($userid));
        $process_list = $process->get_process_list(); // emoncms supported processes
        
        $log = "feed processes:\n";
        
        foreach($feeds as $f) {
            // for each feed
        	if (($f->engine == Engine::VIRTUALFEED) && (isset($f->processList) || isset($f->processlist))) {
        		$processes = isset($f->processList) ? $f->processList : $f->processlist;
                $feedid = $f->feedid;
                $result = $this->convert_processes($feeds, $inputs, $processes, $process_list);
                if (isset($result["success"])) {
                    $log .= "-- SET ERROR $feedid ".$result["message"];
                }

                $processes = implode(",", $result);
                if ($processes != "") {
                    if (!$dryrun) {
                        $this->log->info("create_feeds_processes() calling feed->set_processlist feedId=$feedid processes=$processes");
                        $feed->set_processlist($userid, $feedid, $processes, $process_list);
                    }
                    $log .= "-- SET feedid=$feedid\n";
                    $log .= $this->log_processlist($processes,$input,$feed,$process_list);
                }
            }
        }
        
        return $log."\n";
    }

    // Converts template processList
    protected function convert_processes($feeds, $inputs, $processes, $process_list){
        $result = array();
        
        if (is_array($processes)) {
            $process_list_by_name = array();
            foreach ($process_list as $process_id => $process_item) {
                $name = $process_item[2];
                $process_list_by_name[$name] = $process_id;
            }

            // create each processList
            foreach($processes as $p) {
                $proc_name = $p->process;
                
                // If process names are used map to process id
                if (isset($process_list_by_name[$proc_name])) $proc_name = $process_list_by_name[$proc_name];
                
                if (!isset($process_list[$proc_name])) {
                    $this->log->error("convertProcess() Process '$proc_name' not supported. Module missing?");
                    return array('success'=>false, 'message'=>"Process '$proc_name' not supported. Module missing?");
                }

                // Arguments
                if(isset($p->arguments)) {
                    if(isset($p->arguments->type)) {
                        $type = @constant($p->arguments->type); // ProcessArg::
                        $process_type = $process_list[$proc_name][1]; // get emoncms process ProcessArg

                        if ($process_type != $type) {
                            $this->log->error("convertProcess() Bad device template. Missmatch ProcessArg type. Got '$type' expected '$process_type'. process='$proc_name' type='".$p->arguments->type."'");
                            return array('success'=>false, 'message'=>"Bad device template. Missmatch ProcessArg type. Got '$type' expected '$process_type'. process='$proc_name' type='".$p->arguments->type."'");
                        }

                        if (isset($p->arguments->value)) {
                            $value = $p->arguments->value;
                        } else if ($type === ProcessArg::NONE) {
                            $value = 0;
                        } else {
                            $this->log->error("convertProcess() Bad device template. Undefined argument value. process='$proc_name' type='".$p->arguments->type."'");
                            return array('success'=>false, 'message'=>"Bad device template. Undefined argument value. process='$proc_name' type='".$p->arguments->type."'");
                        }

                        if ($type === ProcessArg::VALUE) {
                        } else if ($type === ProcessArg::INPUTID) {
                            $temp = $this->search_array($inputs, 'name', $value); // return input array that matches $inputArray[]['name']=$value
                            if ($temp->inputid > 0) {
                                $value = $temp->inputid;
                            } else {
                                $this->log->error("convertProcess() Bad device template. Input name '$value' was not found. process='$proc_name' type='".$p->arguments->type."'");
                                return array('success'=>false, 'message'=>"Bad device template. Input name '$value' was not found. process='$proc_name' type='".$p->arguments->type."'");
                            }
                        } else if ($type === ProcessArg::FEEDID) {
                            $temp = $this->search_array($feeds, 'name', $value); // return feed array that matches $feedArray[]['name']=$value
                            if ($temp->feedid > 0) {
                                $value = $temp->feedid;
                            } else {
                                $this->log->error("convertProcess() Bad device template. Feed name '$value' was not found. process='$proc_name' type='".$p->arguments->type."'");
                                return array('success'=>false, 'message'=>"Bad device template. Feed name '$value' was not found. process='$proc_name' type='".$p->arguments->type."'");
                            }
                        } else if ($type === ProcessArg::NONE) {
                            $value = 0;
                        } else if ($type === ProcessArg::TEXT) {
//                      } else if ($type === ProcessArg::SCHEDULEID) { //not supporte for now
                        } else {
                                $this->log->error("convertProcess() Bad device template. Unsuported argument type. process='$proc_name' type='".$p->arguments->type."'");
                                return array('success'=>false, 'message'=>"Bad device template. Unsuported argument type. process='$proc_name' type='".$p->arguments->type."'");
                        }

                    } else {
                        $this->log->error("convertProcess() Bad device template. Argument type is missing, set to NONE if not required. process='$proc_name' type='".$p->arguments->type."'");
                        return array('success'=>false, 'message'=>"Bad device template. Argument type is missing, set to NONE if not required. process='$proc_name' type='".$p->arguments->type."'");
                    }

                    $this->log->info("convertProcess() process process='$proc_name' type='".$p->arguments->type."' value='" . $value . "'");
                    $result[] = $proc_name.":".$value;

                } else {
                    $this->log->error("convertProcess() Bad device template. Missing processList arguments. process='$proc_name'");
                    return array('success'=>false, 'message'=>"Bad device template. Missing processList arguments. process='$proc_name'");
                }
            }
        }
        return $result;
    }

    protected function search_array($array, $key, $val) {
        foreach ($array as $item) {
            if (isset($item->$key) && $item->$key == $val) {
                return $item;
            }
        }
        return null;
    }
    
    private function log_processlist($processes,$input,$feed,$process_list) {
        $log = "";
        $process_parts = explode(",",$processes);
        foreach ($process_parts as $pair) {
            $pair = explode(":",$pair);
            $pid = $pair[0]; 
            $arg = $pair[1];
            
            if ($process_list[$pid][1]==ProcessArg::INPUTID) {
                $i = $input->get_details($arg);
                $arg = $i['nodeid'].":".$i['name'];
            }
            else if ($process_list[$pid][1]==ProcessArg::FEEDID) {
                $f = $feed->get($arg);
                $arg = $f['tag'].":".$f['name'];
            }
            $log .= "   ".$process_list[$pid][2]." ".$arg."\n";
        }
        return $log;
    }
}
