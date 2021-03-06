<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AMOS script to parse English strings in the core
 *
 * This is supposed to be run regularly in a cronjob to register all changes
 * done in Moodle source code.
 *
 * @package   local_amos
 * @copyright 2010 David Mudrak <david.mudrak@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

set_time_limit(0);
$starttime = microtime();

require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once($CFG->dirroot . '/local/amos/cli/config.php');
require_once($CFG->dirroot . '/local/amos/mlanglib.php');
require_once($CFG->dirroot . '/local/amos/locallib.php');
require_once($CFG->dirroot . '/local/amos/renderer.php');

/**
 * This is a hacky way how to populate a forum at lang.moodle.org with commits into the core
 *
 * @param mlang_stage $stage
 * @param string $commitmsg
 * @param string $committer
 * @param string $committeremail
 * @param string $commithash
 * @param string $fullcommitmsg
 * @return void
 */
function amos_core_commit_notify(mlang_stage $stage, $commitmsg, $committer, $committeremail, $commithash, $fullcommitmsg) {
    global $CFG; $DB;
    require_once($CFG->dirroot.'/mod/forum/lib.php');

    if ($CFG->wwwroot !== 'https://lang.moodle.org') {
        // this is intended for lang.moodle.org portal only
        return;
    }

    if (!$stage->has_component()) {
        // nothing to commit
        return;
    }

    // these are hard-coded values of a forum to inject commit messages into
    $courseid = 2;  // course 'Translating Moodle'
    $cmid = 7;      // forum 'Notification of string changes'
    $userid = 2;    // user 'AMOS bot'

    $cm = get_coursemodule_from_id('forum', $cmid);

    $discussion = new stdclass();
    $discussion->course = $courseid;
    $discussion->forum = $cm->instance;
    $discussion->name = substr(s('[AMOS commit] ' . $commitmsg), 0, 255);
    $discussion->message = 'Author: ' . $committer . "\n";
    $discussion->message .= $fullcommitmsg . "\n\n";
    $discussion->message .= 'http://git.moodle.org/gw?p=moodle.git;a=commit;h='.$commithash . "\n";
    $discussion->message .= 'http://github.com/moodle/moodle/commit/'.$commithash . "\n\n";

    $standardplugins = local_amos_standard_plugins();

    foreach ($stage->get_iterator() as $component) {
        foreach ($component->get_iterator() as $string) {
            if ($string->deleted) {
                $sign = '-  ';
            } else {
                $sign = '+  ';
            }

            if (isset($standardplugins[$component->version->dir][$component->name])) {
                $name = $standardplugins[$component->version->dir][$component->name];
            } else {
                $name = $component->name;
            }

            $discussion->message .= $sign . $component->version->dir . ' en [' . $string->id . ',' . $name . "]\n";
        }
    }

    $discussion->message = s($discussion->message);
    $discussion->messageformat = FORMAT_MOODLE;
    $discussion->messagetrust = 0;
    $discussion->attachments = null;
    $discussion->mailnow = 1;

    $message = null;
    forum_add_discussion($discussion, null, $message, $userid);
}

/**
 * This is a helper function that just contains a block of code that needs to be
 * executed from two different places in this script. Consider it more as C macro
 * than a real function.
 */
function amos_parse_core_commit() {
    global $DB;
    global $stage, $realmodified, $timemodified, $commitmsg, $committer, $committeremail, $commithash,
           $version, $fullcommitmsg, $startatlock, $affected;

    // if there is nothing to do, just store the last hash processed and return
    // this is typical for git commits that do not touch any lang file
    if (!$stage->has_component()) {
        file_put_contents($startatlock, $commithash);
        return;
    }

    if (empty($commithash)) {
        throw new coding_exception('When storing strings from a git commit, the hash must be provided');
    }

    // this is a hacky check to make sure that the git commit has not been processed already
    // this helps to prevent situations when a commit is reverted and AMOS is adding
    // and removing strings in sort of loop
    if ($DB->record_exists('amos_commits', array('source' => 'git', 'commithash' => $commithash))) {
        $stage->clear();
        fputs(STDOUT, "SKIP $commithash has already been processed before\n");
        file_put_contents($startatlock, $commithash);
        return;
    }

    // rebase the stage so that it contains just real modifications of strings
    $stage->rebase($timemodified, true, $timemodified);

    // make sure that the strings to be removed are really affected by the commit
    foreach ($stage->get_iterator() as $component) {
        foreach ($component->get_iterator() as $string) {
            if (!isset($affected[$component->name][$string->id])) {
                $component->unlink_string($string->id);
            }
        }
    }

    // rebase again to get rid of eventually empty components that were
    // left after removing unaffected strings
    $stage->rebase($timemodified, false);

    // if there is nothing to do now, just store the last has processed and return.
    // this is typical for git commits that touch some lang file but they do not actually
    // modify any string. Note that we do not execute AMOScript in this situation.
    if (!$stage->has_component()) {
        fputs(STDOUT, "NOOP $commithash does not introduce any string change\n");
        file_put_contents($startatlock, $commithash);
        return;
    }

    // ok so it seems we finally have something to do here! let's spam the world first
    amos_core_commit_notify($stage, $commitmsg, $committer, $committeremail, $commithash, $fullcommitmsg);
    // actually commit the changes
    $stage->commit($commitmsg, array(
        'source' => 'git',
        'userinfo' => $committer . ' <' . $committeremail . '>',
        'commithash' => $commithash
    ), true, $timemodified);

    // execute AMOS script if the commit message contains some
    if ($version->code >= mlang_version::MOODLE_20) {
        $instructions = mlang_tools::extract_script_from_text($fullcommitmsg);
        if (!empty($instructions)) {
            foreach ($instructions as $instruction) {
                fputs(STDOUT, "EXEC $instruction\n");
                $changes = mlang_tools::execute($instruction, $version, $timemodified);
                if ($changes instanceof mlang_stage) {
                    $changes->rebase($timemodified);
                    $changes->commit($commitmsg, array(
                        'source' => 'commitscript',
                        'userinfo' => $committer . ' <' . $committeremail . '>',
                        'commithash' => $commithash
                    ), true, $timemodified);
                } elseif ($changes < 0) {
                    fputs(STDERR, "EXEC STATUS $changes\n");
                }
                unset($changes);
            }
        }
    }

    // remember the processed commithash
    file_put_contents($startatlock, $commithash);
}

$tmp = make_upload_directory('amos/temp');
$var = make_upload_directory('amos/var');
$mem = memory_get_usage();

// the following commits contains a syntax typo and they can't be included for processing. They are skipped
$MLANG_BROKEN_CHECKOUTS = array(
    '52425959755ff22c733bc39b7580166f848e2e2a_lang_en_utf8_enrol_authorize.php',
    '46702071623f161c4e06ee9bbed7fbbd48356267_lang_en_utf8_enrol_authorize.php',
    '1ec0ef254c869f6bd020edafdb78a80d4126ba79_lang_en_utf8_role.php',
    '8871caf0ac9735b67200a6bdcae3477701077e63_lang_en_utf8_role.php',
    '50d30259479d27c982dabb5953b778b71d50d848_lang_en_utf8_countries.php',
    'e783513693c95d6ec659cb487acda8243d118b84_lang_en_utf8_countries.php',
    '5e924af4cac96414ee5cd6fc22b5daaedc86a476_lang_en_utf8_countries.php',
    'c2acd8318b4e95576015ccc649db0f2f1fe980f7_lang_en_utf8_grades.php',
    '5a7e8cf985d706b935a61366a0c66fd5c6fb20f9_lang_en_utf8_grades.php',
    '8e9d88f2f6b5660687c9bd5decbac890126c13e5_lang_en_utf8_debug.php',
    '1343697c8235003a91bf09ad11ab296f106269c7_lang_en_utf8_error.php',
    'c5d0eaa9afecd924d720fbc0b206d144eb68db68_lang_en_utf8_question.php',
    '06e84d52bd52a4901e2512ea92d87b6192edeffa_lang_en_utf8_error.php',
    '4416da02db714807a71d8a28c19af3a834d2a266_lang_en_utf8_enrol_mnet.php',
    'fd1d5455fde49baa64a37126f25f3d3fd6b6f3f2_mod_assignment_lang_en_assignment.php',
    '759b81f3dc4c2ce2b0579f8764aabf9e3fa9d0cc_theme_nonzero_lang_en_theme_nonzero.php',
    '5de15b83cc41c1f03415db00088b0c0d294556a9_mod_lti_lang_en_lti.php',
);

$MLANG_IGNORE_COMMITS = array(
    // the following are MDL-21694 commits that just move the lang files. such a move is registered
    // as a deletion and re-addition of every string which is usually useless
    '9d68aee7860398345b3921b552ccaefe094d438a',
    '5f251510549671a3864427e4ea161b8bd62d0df9',
    '60b00b6d99f10c084375d09c244f0011baabdec9',
    'f312fe1a9e00abe1f79348d1092697a485369bfb',
    '05162f405802faf006cac816443432d29e742458',
    '57223fbe95df69ebb9831ff681b89ec67de850ff',
    '7ae8954a02ebaf82f74e2842e4ad17c05f6af6a8',
    '1df58edc0f25db3892950816f6b9edb2de693a2c',
    'd8753184ec66575cffc834aaeb8ac25477da289b',
    '200fe7f26b1ba13d9ac63f073b6676ce4abd2976',
    '2476f5f22c2bfaf0626a7e1e8af0ffee316b01b4',
    'd8a81830333d99770a6072ddc0530c267ebddcde',
    'afbbc6c0f6667a5af2a55aab1319f3be00a667f1',
    '3158eb9350ed79c3fe81919ea8af67418de18277',
    'ffbb41f48f9c317347be4288771db84e36bfdf22',
    '81144b026f80665a7d7ccdadbde4e8f99d91e806',
    '675aa51db3038b629c7350a53e43f20c5d414045',
    'dee576ebbaece98483acfa401d459f62f0f0387d',
    'eea1d899bca628f9c5e0234068beb713e81a64fd',
    'ce0250ec19cf29479b36e17541c314030a2f9ab5',
    'bda5f9b1159bff09006dac0bcfaec1ec788f134c',
    '89422374d1944d4f5fff08e2187f2c0db75aaefc',
    'b4340cb296ce7665b6d8f64885aab259309271a6',
    '001fa4b3135b27c2364845a221d11ea725d446a0',
    'c811222ff9b1469633f7e8dbf6b06ddccafb8dbd',
    '7a4ddc172ae46014ee2ebb5b9f4ee2ada2cd7e1e',
    'bc3755be21025c5815de19670eb04b0875f5fa31',
    '96b838efa990d6a6a2db0050d9deeceeda234494',
    'cb9dc45c36ffbbdee1a0f22a50b4f31db47a5eb6',
    '33aadb2d70c4e8381281b635a9012f3f0673d397',
    '34970b7fc6c4932b15426ea80ad94867a1e1bb5b',
    '7a563f0f3586a4bc5b5263492282734410e01ee0',
    'b13af519fc48ee9d8b1e801c6056519454bf8400',
    'd1f62223b59d6acb1475d3979cdafda726cc1290',
    '2064cbaa0f6ea36fc5803fcebb5954ef8c642ac4',
    // the following commit renames en_utf8 back to en
    // we are ignoring that
    '3a915b066765efc3cc166ae8186405f67c04ec2c',
    // the following commits just move a string file
    '34d6a78987fa61f81bf37f5c4c2ee3e7a01d4d1c',
    '8118207b6fd8607eeca1aa7bef327e8280e3e5f8',
    // the removal of mod_hotpot from core:
    '91b9560bd63e5582781e910573ee0887b558ca12',
);

$MLANG_PARSE_BRANCHES = array(
    'MOODLE_31_STABLE',
    'MOODLE_32_STABLE',
    'MOODLE_33_STABLE',
    'MOODLE_34_STABLE',
    'MOODLE_35_STABLE',
);

$standardplugins = local_amos_standard_plugins();
$stage = new mlang_stage();

fputs(STDOUT, "*****************************************\n");
fputs(STDOUT, date('Y-m-d H:i', time()));
fputs(STDOUT, " PARSE CORE JOB STARTED\n");

foreach ($MLANG_PARSE_BRANCHES as $branch) {
    fputs(STDOUT, "=========================================\n");
    fputs(STDOUT, "BRANCH {$branch}\n");
    if ($branch == 'MOODLE_35_STABLE') {
        $gitbranch = 'origin/master';
    } else {
        $gitbranch = 'origin/' . $branch;
    }
    $version = mlang_version::by_branch($branch);

    $startatlock = "{$var}/{$branch}.startat";
    $startat = '';
    if (file_exists($startatlock)) {
        $startat = trim(file_get_contents($startatlock));
        if (!empty($startat)) {
            $startat = '^' . $startat . '^';
        }
    } else {
        fputs(STDERR, "Missing {$branch} branch startat point\n");
        continue;
    }

    // XXX if the reply of all AMOS scripts is needed (for example during the initial fetch of the strings),
    // freeze the start point at MOODLE_20_STABLE here - this is the first commit containing AMOS script
    //if ($branch == 'MOODLE_20_STABLE') {
    //    $startat = '^61bb07c2573ec711a0e5d1ccafa313cf47b9fc22^';
    //}

    chdir(AMOS_REPO_MOODLE);
    $gitout = array();
    $gitstatus = 0;
    $gitcmd = AMOS_PATH_GIT . " whatchanged --topo-order --reverse --format='format:COMMIT:%H TIMESTAMP:%at' {$gitbranch} {$startat}";
    fputs(STDOUT, "RUN {$gitcmd}\n");
    exec($gitcmd, $gitout, $gitstatus);

    if ($gitstatus <> 0) {
        // error occured
        fputs(STDERR, "RUN ERROR {$gitstatus}\n");
        exit(1);
    }

    $commithash = '';
    $committime = '';
    $affected   = array();
    foreach ($gitout as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }
        if (substr($line, 0, 7) == 'COMMIT:') {
            if (!empty($commithash)) {
                // new commit is here - if we have something to push into AMOS repository, do it now
                amos_parse_core_commit();
            }
            $commithash   = substr($line, 7, 40);
            $committime   = substr($line, 58);      // the original git commit's timestamp
            $timemodified = time();                 // when the commit was processed by AMOS
            $affected     = array();                // explicit list of strings affected by the commit
            continue;
        }
        if (in_array($commithash, $MLANG_IGNORE_COMMITS)) {
            fputs(STDOUT, "IGNORED {$commithash}\n");
            continue;
        }
        $parts = explode("\t", $line);
        $changetype = substr($parts[0], -1);    // A (added new file), M (modified), D (deleted)
        $file = $parts[1];
        $componentname = mlang_component::name_from_filename($file);
        // series of checks that the file is proper language pack
        if (($version->code >= mlang_version::MOODLE_20) and ($committime >= 1270884296)) {
            // since Petr's commit 3a915b066765efc3cc166ae8186405f67c04ec2c
            // on 10th April 2010, strings are in 'en' folder again
            $enfolder = 'en';
        } else {
            $enfolder = 'en_utf8';
        }
        if (strpos($file, "lang/$enfolder/") === false) {
            // this is not a language file
            continue;
        }
        if (strpos($file, "lang/$enfolder/docs/") !== false or strpos($file, "lang/$enfolder/help/") !== false) {
            // ignore
            continue;
        }
        if (strpos($file, 'portfolio_format_leap2a.php') !== false) {
            // MDL-22212
            continue;
        }
        if (substr($file, -4) !== '.php') {
            // this is not a valid string file
            continue;
        }
        if (substr($file, 0, 13) == 'install/lang/') {
            // ignore these auto generated files
            fputs(STDOUT, "SKIP installer bootstrap strings\n");
            continue;
        }
        if (strpos($file, '/tests/fixtures/') !== false) {
            // This is a string file that is part of unit tests, ignore it.
            fputs(STDOUT, "SKIP unit test fixture file\n");
            continue;
        }
        if (isset($standardplugins[$version->dir])) {
            // for 2.0 and higher we can make sure that we parse only standard component
            if (!isset($standardplugins[$version->dir][$componentname])) {
                fputs(STDERR, "WARNING non-standard component on this branch ($componentname)\n");
            }
        }

        $memprev = $mem;
        $mem = memory_get_usage();
        $memdiff = $memprev < $mem ? '+' : '-';
        $memdiff = $memdiff . abs($mem - $memprev);
        fputs(STDOUT, "FOUND {$commithash} {$changetype} {$file} [{$mem} {$memdiff}]\n");

        // get some additional information of the commit
        $format = implode('%n', array('%an', '%ae', '%at', '%s', '%b')); // name, email, timestamp, subject, body
        $commitinfo = array();
        $gitcmd = AMOS_PATH_GIT . " log --format={$format} {$commithash} ^{$commithash}~";
        exec($gitcmd, $commitinfo);
        $committer      = $commitinfo[0];
        $committeremail = $commitinfo[1];
        $realmodified   = $commitinfo[2]; // the real timestamp of the commit - should be the same as $committime
        $commitmsg      = iconv('UTF-8', 'UTF-8//IGNORE', $commitinfo[3]);
        $commitmsg     .= "\n\nCommitted into Git: " . local_amos_renderer::commit_datetime($realmodified);
        $fullcommitmsg  = implode("\n", array_slice($commitinfo, 3));  // AMOS script is looked up here later

        if ($changetype == 'D') {
            // whole file removal - do not do anything, fix-drift job will clear the repository
            continue;
        }

        // get the list of strings affected by the commit
        $gitcmd = AMOS_PATH_GIT . " log -1 -p --format=format: " . $commithash . ' -- ' . $file;
        $diff = array();
        exec($gitcmd, $diff);
        foreach (mlang_tools::get_affected_strings($diff) as $stringid) {
            $affected[$componentname][$stringid] = true;
        }

        // dump the given revision of the file to a temporary area
        $checkout = $commithash . '_' . str_replace('/', '_', $file);
        if (in_array($checkout, $MLANG_BROKEN_CHECKOUTS)) {
            fputs(STDOUT, "BROKEN $checkout\n");
            continue;
        }
        $checkout = $tmp . '/' . $checkout;
        exec(AMOS_PATH_GIT . " show {$commithash}:{$file} > {$checkout}");

        // convert the php file into strings in the staging area
        if ($version->code >= mlang_version::MOODLE_20) {
            if ($committime >= 1270908105) {
                // since David's commit 30c8dd34f70437b15bd7960eb056d8de0c5e0375
                // on 10th April 2010, strings are in the new format
                $checkoutformat = 2;
            } else {
                $checkoutformat = 1;
            }
        } else {
            $checkoutformat = 1;
        }
        $component = mlang_component::from_phpfile($checkout, 'en', $version, $timemodified, $componentname, $checkoutformat);
        $stage->add($component);
        $component->clear();
        unset($component);
        unlink($checkout);

    }
    // we just parsed the last git commit at this branch - let us commit what we have
    amos_parse_core_commit();
}

fputs(STDOUT, date('Y-m-d H:i', time()));
fputs(STDOUT, " PARSE CORE JOB DONE\n");
