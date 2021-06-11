<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


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

$ks = [];

$data = file_get_contents('php://input');

$pbv = json_decode($data);
//echo(count($pbv['storage']['physical']));
//print_r($pbv->vm_storage->physical);



//build ignoredisk string, disks with > 0 partitions or lvm set to true (use whole disk) will be excluded
$ignoredisks = [];
for($i = 0; $i <= count($pbv->vm_storage->physical) - 1; $i++) {
    if(property_exists($pbv->vm_storage->physical[$i], 'lvm') && $pbv->vm_storage->physical[$i]->lvm===true) {
        array_push($ignoredisks, str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk));
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
        array_push($ks, 'part pv.' . $i . ' --grow --onpart="' . str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk) . '"');
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
                array_push($ks, 'part ' . $pbv->vm_storage->physical[$i]->parts[$j]->mountpoint . ' --fstype="' . $pbv->vm_storage->physical[$i]->parts[$j]->fstype . '" --size="' . $pbv->vm_storage->physical[$i]->parts[$j]->size_mb . '"');
            } else {
                if(property_exists($pbv->vm_storage->physical[$i]->parts[$j], 'lvm') && $pbv->vm_storage->physical[$i]->parts[$j]->lvm===true) {
                    array_push($ks, 'part pv.' . $i . $j . ' ' . $size . ' --ondisk="' . str_replace("/dev/", "", $pbv->vm_storage->physical[$i]->disk) . '"');
                }
            }
        }
    }
}


$vgs = [];
$vgline = [];
for($i = 0; $i <= count($pbv->vm_storage->logical->vgs) - 1; $i++) {
    $pvs = [];
    for($j = 0; $j <= count($pbv->vm_storage->logical->vgs[$i]->pvs) - 1; $j++) {
        $pv = lookupPV($pbv, $pbv->vm_storage->logical->vgs[$i]->pvs[$j]);
        if($pv) {
            array_push($pvs, $pv);
        } else {
            http_response_code(400);
            echo "PV \'" . $pbv->vm_storage->logical->vgs[$i]->pvs[$j] . "\' cannot be found";
            exit;
        }
    }

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
}


$lvs = [];
for($i = 0; $i <= count($pbv->vm_storage->logical->vgs) - 1; $i++) {
    for($j = 0; $j <= count($pbv->vm_storage->logical->vgs[$i]->lvs) - 1; $j++) {
        //print_r($pbv->vm_storage->logical->vgs[$i]->lvs[$j]);
        array_push($ks, 'logvol ' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->mountpoint . ' --size="' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->size_mb . '" --vgname="' . $pbv->vm_storage->logical->vgs[$i]->name . '" --name="' . $pbv->vm_storage->logical->vgs[$i]->lvs[$j]->name . '"');
    }
}



//array_push($ks, 'firstboot --disable');
if(property_exists($pbv, 'ks_rootpw')) {
    array_push($ks, 'rootpw --iscrypted ' . $pbv->ks_rootpw);
}

array_push($ks, '%packages');
//array_push($ks, '@core');
array_push($ks, '@^minimal-environment');
array_push($ks, 'kexec-tools');
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

$resp = implode($ks, "\n");
$hash = hash("sha1", $resp);

$file = fopen("./kickstarts/" . $hash . ".ks", "w");

fwrite($file, $resp);
fclose($file);

header("Link: /kickstarts/". $hash . ".ks");

echo $resp;

?>