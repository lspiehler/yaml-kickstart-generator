<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$stripedpvs = array();

function base64UrlEncode($data) {
    $base64Url = strtr(base64_encode($data), '+/', '-_');
 
    return rtrim($base64Url, '=');
}
 
function base64UrlDecode( $base64Url) {
    return base64_decode(strtr($base64Url, '-_', '+/'), true);
}

function lookupPV($pbv, $pv) {
    for($i = 0; $i <= count($pbv->vm_storage->physical) - 1; $i++) {
        if($pbv->vm_storage->physical[$i]->disk==$pv) {
            return 'pv.'. $i;
        }
        for($j = 0; $j <= count($pbv->vm_storage->physical[$i]->parts) - 1; $j++) {
            if($pbv->vm_storage->physical[$i]->parts[$j]->part==$pv) {
                return 'pv.'. $i . $j;
            }
        }
    }
    return false;
}


header("Content-Type: text/plain");

if(isset($_GET['data'])) {
    $compressed = base64UrlDecode($_GET['data']);
    echo bzdecompress($compressed);
    exit;
}

$ks = [];
$unsupportedlvm = [];

$data = file_get_contents('php://input');

$pbv = json_decode($data);
//echo(count($pbv['storage']['physical']));
//print_r($pbv->vm_storage->physical);

//populate stripedpvs array with pvs that will be in vgs with striped lvs
for($i = 0; $i <= count($pbv->vm_storage->logical->vgs) - 1; $i++) {
    for($j = 0; $j <= count($pbv->vm_storage->logical->vgs[$i]->lvs) - 1; $j++) {
        if(property_exists($pbv->vm_storage->logical->vgs[$i]->lvs[$j], 'layout')) {
            if($pbv->vm_storage->logical->vgs[$i]->lvs[$j]->layout == 'striped' || $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->layout == 'mirror') {
                for($k = 0; $k <= count($pbv->vm_storage->logical->vgs[$i]->pvs) - 1; $k++) {
                    array_push($stripedpvs, $pbv->vm_storage->logical->vgs[$i]->pvs[$k]);
                }
                break;
            }
        }
    }
}

//print_r($stripedpvs);

//build ignoredisk string, disks with > 0 partitions or lvm set to true (use whole disk) will be excluded
$ignoredisks = [];
for($i = 0; $i <= count($pbv->vm_storage->physical) - 1; $i++) {
    if(property_exists($pbv->vm_storage->physical[$i], 'lvm') && $pbv->vm_storage->physical[$i]->lvm===true) {
        //ignore disks that use non-linear lvm layouts because the will be created in a bash script outside of kickstart
        //if(!in_array($pbv->vm_storage->physical[$i]->disk, $stripedpvs)) {
            array_push($ignoredisks, str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk));
        //}
    } else {
        if(property_exists($pbv->vm_storage->physical[$i], 'parts')) {
            if(count($pbv->vm_storage->physical[$i]->parts) >= 1) {
                array_push($ignoredisks, str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk));
            }
        }
    }
}
array_push($ks, 'ignoredisk --only-use=' . implode($ignoredisks, ","));



//get boot disk and verify
$bootdisk = [];
for($i = 0; $i <= count($pbv->vm_storage->physical) - 1; $i++) {
    if(property_exists($pbv->vm_storage->physical[$i], 'boot')) {
        if($pbv->vm_storage->physical[$i]->boot===true) {
            array_push($bootdisk, str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk));
        }
    }
}
if(count($bootdisk) == 1) {
    array_push($ks, 'bootloader --location=mbr --boot-drive=' . $bootdisk[0] . ' --append="console=tty0 console=ttyS0,119200n8"');
} elseif(count($bootdisk) < 1) {
    http_response_code(400);
    echo "No boot disk has been specified";
    exit;
} else {
    http_response_code(400);
    echo "Only one boot disk can be specified";
    exit;
}



if(property_exists($pbv, 'ks_lang')) {
    array_push($ks, 'lang ' . $pbv->ks_lang);
}

if(property_exists($pbv, 'ks_keyboard')) {
    array_push($ks, 'keyboard ' . $pbv->ks_keyboard);
}

if(property_exists($pbv, 'ks_time')) {
    $timeline = [];
    if(property_exists($pbv->ks_time, 'timezone')) {
        array_push($timeline, 'timezone ' . $pbv->ks_time->timezone);
    }
    if(property_exists($pbv->ks_time, 'utc')) {
        if($pbv->ks_time->utc===true) {
            array_push($timeline, '--isUtc');
        }
    }
    if(property_exists($pbv->ks_time, 'ntpservers')) {
        $ntpservers = [];
        for($i = 0; $i <= count($pbv->ks_time->ntpservers) - 1; $i++) {
            array_push($ntpservers, $pbv->ks_time->ntpservers[$i]);
        }
        array_push($timeline, '--ntpservers=' . implode($ntpservers, ','));
    }
    array_push($ks, implode($timeline, ' '));
}



array_push($ks, 'auth --passalgo=sha512 --useshadow');
array_push($ks, 'selinux --disabled');
array_push($ks, 'firewall --disabled');

array_push($ks, 'services --enabled=NetworkManager,sshd');
array_push($ks, 'eula --agreed');
array_push($ks, 'reboot --eject');
if(property_exists($pbv, 'ks_text')) {
    if($pbv->ks_text===true) {
        array_push($ks, 'text');
    }
}



array_push($ks, 'zerombr');
array_push($ks, '');


$parts = [];
for($i = 0; $i <= count($pbv->vm_storage->physical) - 1; $i++) {
    if(property_exists($pbv->vm_storage->physical[$i], 'lvm') && $pbv->vm_storage->physical[$i]->lvm===true) {
        if(in_array($pbv->vm_storage->physical[$i]->disk, $stripedpvs)) {
            array_push($unsupportedlvm, 'pvcreate -y -ff ' . $pbv->vm_storage->physical[$i]->disk . ' &> /dev/tty1');
        } else {
            array_push($ks, 'part pv.' . $i . ' --grow --onpart="' . str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk) . '"');
        }
    } else {
        for($j = 0; $j <= count($pbv->vm_storage->physical[$i]->parts) - 1; $j++) {
            $size;
            if(property_exists($pbv->vm_storage->physical[$i]->parts[$j], 'grow') && $pbv->vm_storage->physical[$i]->parts[$j]->grow===true) {
                $size = '--grow';
            } else {
                if(property_exists($pbv->vm_storage->physical[$i]->parts[$j], 'size_mb')) {
                    $size = '--size="' . $pbv->vm_storage->physical[$i]->parts[$j]->size_mb . '"';
                } else {
                    http_response_code(400);
                    echo "Either size_mb must be set with a value or grow set to true for each partition";
                    exit;
                }
            }
            //print_r($pbv->vm_storage->physical[$i]->parts[$j]);
            if(property_exists($pbv->vm_storage->physical[$i]->parts[$j], 'fstype')) {
                if(!in_array($pbv->vm_storage->physical[$i]->disk, $stripedpvs)) {
                    array_push($ks, 'part ' . $pbv->vm_storage->physical[$i]->parts[$j]->mountpoint . ' --fstype="' . $pbv->vm_storage->physical[$i]->parts[$j]->fstype . '" --size="' . $pbv->vm_storage->physical[$i]->parts[$j]->size_mb . '"');
                }
            } else {
                if(property_exists($pbv->vm_storage->physical[$i]->parts[$j], 'lvm') && $pbv->vm_storage->physical[$i]->parts[$j]->lvm===true) {
                    if(!in_array($pbv->vm_storage->physical[$i]->disk, $stripedpvs)) {
                        array_push($ks, 'part pv.' . $i . $j . ' ' . $size . ' --ondisk="' . str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk) . '"');
                    }
                }
            }
        }
    }
}


$vgs = [];
$vgline = [];
for($i = 0; $i <= count($pbv->vm_storage->logical->vgs) - 1; $i++) {
    $pvs = [];
    $striped = False;
    for($j = 0; $j <= count($pbv->vm_storage->logical->vgs[$i]->pvs) - 1; $j++) {
        if(in_array($pbv->vm_storage->logical->vgs[$i]->pvs[$j], $stripedpvs)) {
            $striped = True;
            break;
        }
        $pv = lookupPV($pbv, $pbv->vm_storage->logical->vgs[$i]->pvs[$j]);
        if($pv) {
            array_push($pvs, $pv);
        } else {
            http_response_code(400);
            echo "PV \'" . $pbv->vm_storage->logical->vgs[$i]->pvs[$j] . "\' cannot be found";
            exit;
        }
    }

    if($striped === False) {
        array_push($vgline, 'volgroup');
        array_push($vgline, $pbv->vm_storage->logical->vgs[$i]->name);
        if(property_exists($pbv->vm_storage->logical->vgs[$i], 'pesize')) {
            if($pbv->vm_storage->logical->vgs[$i]->pesize) {
                array_push($vgline, '--pesize=' . $pbv->vm_storage->logical->vgs[$i]->pesize);
            }
        }
        array_push($vgline, implode($pvs, ' '));

        array_push($ks, implode($vgline, ' '));
        $vgline = [];
    } else {
        array_push($vgline, 'vgcreate');
        array_push($vgline, $pbv->vm_storage->logical->vgs[$i]->name);
        if(property_exists($pbv->vm_storage->logical->vgs[$i], 'pesize')) {
            if($pbv->vm_storage->logical->vgs[$i]->pesize) {
                array_push($vgline, '-s ' . $pbv->vm_storage->logical->vgs[$i]->pesize . 'K');
            }
        }
        array_push($vgline, implode($pbv->vm_storage->logical->vgs[$i]->pvs, ' '));
        array_push($vgline, ' &> /dev/tty1');
        array_push($unsupportedlvm, implode($vgline, ' '));
        array_push($ks, 'volgroup ' . $pbv->vm_storage->logical->vgs[$i]->name . ' --useexisting');
        $vgline = [];
    }
}


$lvs = [];
for($i = 0; $i <= count($pbv->vm_storage->logical->vgs) - 1; $i++) {
    for($j = 0; $j <= count($pbv->vm_storage->logical->vgs[$i]->lvs) - 1; $j++) {
        $logvol = 'logvol ' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->mountpoint . ' --fstype="' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->fstype . '" --vgname="' . $pbv->vm_storage->logical->vgs[$i]->name . '" --name="' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->name . '"';
        if(property_exists($pbv->vm_storage->logical->vgs[$i]->lvs[$j], 'layout')) {
            if($pbv->vm_storage->logical->vgs[$i]->lvs[$j]->layout == 'striped' || $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->layout == 'mirror') {
                array_push($ks, $logvol . ' --useexisting');
                $lvcreate = ['lvcreate -L ' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->size_mb];
                if($pbv->vm_storage->logical->vgs[$i]->lvs[$j]->layout=='striped') {
                    array_push($lvcreate, ' -i ' . count($pbv->vm_storage->logical->vgs[$i]->pvs));
                    if(property_exists($pbv->vm_storage->logical->vgs[$i]->lvs[$j], 'stripe_size')) {
                        array_push($lvcreate, '-I ' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->stripe_size);
                    }
                } elseif($pbv->vm_storage->logical->vgs[$i]->lvs[$j]->layout=='mirror' || $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->layout=='mirrored') {
                    array_push($lvcreate, '--type mirror');
                    if(property_exists($pbv->vm_storage->logical->vgs[$i]->lvs[$j], 'mirrors')) {
                        array_push($lvcreate, '-m ' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->mirrors);
                    } else {
                        array_push($lvcreate, '-m ' . (count($pbv->vm_storage->logical->vgs[$i]->pvs) - 1));
                    }
                } else {

                }
                if(property_exists($pbv->vm_storage->logical->vgs[$i]->lvs[$j], 'lv_extra_args')) {
                    if($pbv->vm_storage->logical->vgs[$i]->lvs[$j]->lv_extra_args) {
                        array_push($lvcreate, $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->lv_extra_args);
                    }
                }
                array_push($lvcreate, '-n ' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->name);
                array_push($lvcreate, $pbv->vm_storage->logical->vgs[$i]->name);
                array_push($lvcreate, '&> /dev/tty1');
                array_push($unsupportedlvm, implode($lvcreate, ' '));
            } else {
                array_push($ks, $logvol . ' --size="' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->size_mb . '"');
            }
        } else {
            array_push($ks, $logvol . ' --size="' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->size_mb . '"');
        }
    }
}



//array_push($ks, 'firstboot --disable');
if(property_exists($pbv, 'ks_rootpw')) {
    array_push($ks, 'rootpw --iscrypted ' . $pbv->ks_rootpw);
}

array_push($ks, '%packages');

if(property_exists($pbv, 'ks_packages') && count($pbv->ks_packages) > 0) {
    for($i = 0; $i <= count($pbv->ks_packages) - 1; $i++) {
        array_push($ks, $pbv->ks_packages[$i]);
    }
} else {
    array_push($ks, '@^minimal-environment');
    array_push($ks, 'kexec-tools');
}

array_push($ks, '%end');

array_push($ks, '%post --log="/var/log/ks-post.log"');
array_push($ks, "");


if(property_exists($pbv, 'ks_user')) {
    if(!property_exists($pbv->ks_user, 'name')) {
        http_response_code(400);
        echo "A \"name\" property must be supplied for ks_user";
        exit;
    }

    if(!property_exists($pbv->ks_user, 'shell')) {
        http_response_code(400);
        echo "A \"shell\" property must be supplied for ks_user";
        exit;
    }

    if(!property_exists($pbv->ks_user, 'uid')) {
        http_response_code(400);
        echo "A \"uid\" property must be supplied for ks_user";
        exit;
    }

    if(!property_exists($pbv->ks_user, 'ssh_pub_key')) {
        http_response_code(400);
        echo "A \"ssh_pub_key\" property must be supplied for ks_user";
        exit;
    }

    array_push($ks, '/usr/sbin/groupadd -g ' . $pbv->ks_user->gid . ' ' . $pbv->ks_user->name);
    array_push($ks, '/usr/sbin/useradd -u ' . $pbv->ks_user->uid . ' -g ' . $pbv->ks_user->gid . ' -m ' . $pbv->ks_user->name . ' -s ' . $pbv->ks_user->shell);
    array_push($ks, '/bin/mkdir /home/' . $pbv->ks_user->name . '/.ssh');
    array_push($ks, '/bin/chmod 700 /home/' . $pbv->ks_user->name . '/.ssh');
    array_push($ks, '/bin/cat << \'EOF\' >  /home/' . $pbv->ks_user->name . '/.ssh/authorized_keys');
    array_push($ks, $pbv->ks_user->ssh_pub_key);
    array_push($ks, 'EOF');
    array_push($ks, '/bin/chmod 600 /home/' . $pbv->ks_user->name . '/.ssh/authorized_keys');
    array_push($ks, '/bin/chown ' . $pbv->ks_user->name . ':' . $pbv->ks_user->name . ' -R /home/' . $pbv->ks_user->name . '/.ssh');
    array_push($ks, '/usr/bin/chcon -R -t ssh_home_t /home/' . $pbv->ks_user->name . '/.ssh');
    array_push($ks, 'echo -e \'' . $pbv->ks_user->name . '\tALL=(ALL)\tNOPASSWD:\tALL\' > /etc/sudoers.d/' . $pbv->ks_user->name);
}

array_push($ks, "\n%end");


$config = [];

if(count($unsupportedlvm) > 0) {
    array_push($config, "%pre");
    //array_push($config, "#!/bin/bash");
    for($i = 0; $i <= count($unsupportedlvm) - 1; $i++) {
        array_push($config, $unsupportedlvm[$i]);
    }
    array_push($config, "%end");
    array_push($config, "");
}

for($i = 0; $i <= count($ks) - 1; $i++) {
    array_push($config, $ks[$i]);
}

//generate random data to test support for very long URLs (apache's default limit is 8177)
/*
$permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
 
function generate_string($input, $strength = 16) {
    $input_length = strlen($input);
    $random_string = '';
    for($i = 0; $i < $strength; $i++) {
        $random_character = $input[mt_rand(0, $input_length - 1)];
        $random_string .= $random_character;
    }
 
    return $random_string;
}
 
// Output: iNCHNGzByPjhApvn7XBD
for($var = 0; $i <= 60; $i++) {
    array_push($ks, '#' . generate_string($permitted_chars, 100));
}
*/

$resp = implode($config, "\n");

$stateless = False;
if(property_exists($pbv, 'ks_stateless')) {
    if($pbv->ks_stateless) {
        $stateless = True;
    }
}

if($stateless) {
    $bzstr = bzcompress($resp, 9);
    $data = base64UrlEncode($bzstr);

    header("Location: rhel.php?data=" . $data);
} else {

    $hash = hash("sha1", $resp);
    $file = fopen("./kickstarts/" . $hash . ".ks", "w");

    fwrite($file, $resp);
    fclose($file);

    $url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]";

    http_response_code(201);

    header("Link: " . $url . "/ks/kickstarts/". $hash . ".ks");

    echo $resp;
}

?>