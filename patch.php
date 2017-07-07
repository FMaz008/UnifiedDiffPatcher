<?php
/**
 * Translation of the official patch.c command.
 * printf are for debug purpose, comment them.
 *
 * Limitations:
 * - The code structure sucks. The C implementation is intended to be fast, not clear, not modular.
 * - It's impossible to validate the whole patch file BEFORE applying changes
 * - The validation comparison is made line by line, would be nice to compare the blocks to avoid inter-line deletion.
 *
 * see UnifiedDiffPatcher for an attempt on a cleaner PHP implementation. 
 *
 * @author Francois Mazerolle <fmaz008@gmail.com>
 */


/**
 *
 * @param string $patchFile File path
 * @param integer $patch_level -p param of the patch command
 */
function patch($patchFile = '-', $patch_level = '-1'){
    $plus = '+';

    //Load the patch file
    printf("Openning %s\n", $patchFile);
    $fileHandler = fopen($patchFile, "r");
    if (!$fileHandler) {
        throw new Exception(sprintf("Could not open file %s.", $patchFile));
    }

    //Read the patch file
    $newFileName = false;
    $patch_line = fgets($fileHandler); //Do NOT put this line inline in the while statement.
    while ($patch_line !== false) {
        $srcStream = null;
        $dstStream = null;
        $newFileName = null;
        $backupFileName = null;
        $srcCurLine = 1;
        $dstCurLine = 0;
        $dstBegLine = null;
        $badHunkCount = 0;
        $hunkCount =0;
        $copyTrailingLinesFlag = 0;

        //Determine the source file. This is useful to
        //Skip everything upto the "---" marker
        //No need to parse the lines "Only in <dir>", and "diff <args>"
        do {
            // Extract the filename used before the patch was generated
            $sourceFileName = extract_filename($patch_line, $patch_level, "--- ");
            // was old_filename above
            $patch_line = fgets($fileHandler);
            if (!$patch_line) {
                return; //This is the usual method ending, as well as the invalid patch file end.
            }
        } while (!$sourceFileName);

        //The source file has been located
        unset($sourceFileName); //The source file name is irrelevant.


        //Now find what's the output file.
        $newFileName = extract_filename($patch_line, $patch_level, "+++ ");

        if (!$newFileName) {
            throw new Exception("Invalid patch");
        }

        printf("Processing %s\n", $newFileName);

        //Make sure the file exists
        if (!file_exists($newFileName)) {
            /*
            $slash = substr($newFileName, 0, strrpos($newFileName, '/'));
            if ($slash) {
                //Create leading directories
                @mkdir($slash, 0644, true);
            }
            $backupFileName = null;
            $srcStream = null;
            */
            throw new Exception(sprintf("Could not open file %s.", $newFileName));
        } else {
            $backupFileName = sprintf("%s.orig", $newFileName);
            rename($newFileName, $backupFileName);
            $srcStream = fopen($backupFileName, 'r');
        }
        $dstStream = fopen($newFileName, 'w');



        //The newFileName has been found, let's patch.



        //Handle all hunks for this file
        $patch_line = fgets($fileHandler); //Do NOT put this line inline in the while statement.
        while ($patch_line !== false) {//hunk loop
            $count = null;
            $srcBegLine = null;
            $hunkOffsetStart = null;
            $srcLastLine = 1;
            $dstLastLine = 1;

            $hunkTxt = rtrim($patch_line, "\n");
            $s1 = sscanf($patch_line, "@@ -%d,%d +%d,%d", $srcBegLine, $srcLastLine, $dstBegLine, $dstLastLine);
            $s2 = sscanf($patch_line, "@@ -%d +%d,%d", $srcBegLine, $dstBegLine, $dstLastLine);

            if ($s1 < 3 && $s2 < 2) {
                //No more hunks for this file
                break;
            }

            if ($plus != '+') {
                //Reverse patch
                $tmp = $srcLastLine;
                $srcLastLine = $dstLastLine;
                $dstLastLine = $tmp;

                $tmp = $srcBegLine;
                $srcBegLine = $dstBegLine;
                $dstBegLine = $tmp;
            }
            $hunkCount++;

            printf("Processing hunk #%u\n", $hunkCount);

            if ($srcBegLine && $dstBegLine) {
                //Copy unmodified lines upto start of hunk
                //srcBegLine will be 0 if it's a new file
                $count = $srcBegLine - $srcCurLine;
                if (copyLines($srcStream, $dstStream, $count)) {
                    throw new Exception("Bad src file");
                }
                $srcCurLine += $count;
                $dstCurLine += $count;
                $copyTrailingLinesFlag = 1;
            }
            $srcLastLine += $hunkOffsetStart = $srcCurLine;
            $dstLastLine += $dstCurLine;

            while (1) {
                unset($patch_line);
                $patch_line = fgets($fileHandler);
                if ($patch_line == null) {
                    break; //EOF - End Of File
                }
                if ($patch_line[0] != '-' && $patch_line[0] != '+' && $patch_line[0] != ' ') {
                    break; //End of hunk
                }

                if ($patch_line[0] != $plus) { // '-' or '+'
                    $diff = false;
                    if ($srcCurLine == $srcLastLine) {
                        break;
                    }
                    $expectation = substr($patch_line, 1);
                    if ($srcStream) {
                        $srcLine = fgets($srcStream);
                        if ($srcLine) {
                            $diff = strcmp($srcLine, $expectation);
                            $srcCurLine++;
                        }
                    }
                    if ($diff === false) {
                        throw new Exception(sprintf("hunk #%u FAILED at %u (%s).\n-\"%s\"\n+\"%s\"", $hunkCount, $hunkOffsetStart, $hunkTxt, $expectation, $srcLine));
                        $badHunkCount++;
                        break;
                    }
                    if($patch_line[0] != ' ') { // '-'
                        continue;
                    }
                }
                if ($dstCurLine == $dstLastLine) {
                    break;
                }
                printf(".");
                fwrite($dstStream, substr($patch_line, 1));
                $dstCurLine++;
            }// End of while loop handling one hunk
            printf("\n");
        }// End of while loop handling one file

        //Cleanup last patched file
        if ($copyTrailingLinesFlag) {
            printf("Copying unmodified end of file\n");
            copyLines($srcStream, $dstStream, PHP_INT_MAX);
            printf("\n");
        }
        if ($srcStream) {
            fclose($srcStream);
        }
        fclose($dstStream);
        if ($badHunkCount) {
            $ret = 1;
            throw new Exception(sprintf("%u out of %u hunk FAILED", $badHunkCount, $hunkCount));
        } else {
            //It worked, we can remove the backup
            if ($backupFileName) {
                unlink($backupFileName);
            }
            if ($dstCurLine == 0 || $dstBegLine == 0) {
                //The new patched file is empty, remove it
                unlink($newFileName);
                //Old file name and new file name may be the same file
                //unlink($oldFileName);
            }
        }
        unset($backupFileName);
        unset($newFileName);

        printf("\n");
    } // End of "while there are patch line"

    return true;
}




/**
 * This method extract the file name from the "+++ " or "--- " line.
 * If will consider a patch_level option that allow to edit the root
 * of the recursion. This is the -p option of the patch command.
 *
 * If patch_level is -1 it will remove all directory names
 * char *line must be greater than 4 chars
 * returns NULL if the file doesnt exist or error
 *
 * @param integer|string $line
 * @param integer $patch_level
 * @param string $pat
 * @return string
 */
function extract_filename($line, $patch_level, $pat) {
    $temp = false;

    if (strncmp($line, $pat, 4) == 0) {

        /* Terminate string at end of source filename */
        $line = strstr($line, "\t", true);

        //Remove the first 4 chr. ( '--- ' or '+++ ' )
        $temp = substr($line, 4);

        /* Skip over (patch_level) number of leading directories */
        while ($patch_level--) {
            $cut = strstr($temp, '/');
            if (!$cut) {
                break;
            }
            $temp = ltrim($cut, '/');
        }
    }
    return $temp;
}



/**
 *
 * @param type $srcStream Source stream
 * @param type $dstStream Destination stream
 * @param type $linesCount Line count
 * @return Line Count
 */
function copyLines(&$srcStream, &$dstStream, $linesCount) {
    while ($srcStream && $linesCount) {
        $line = null;
        $line = fgets($srcStream);
        if ($line == NULL) {
            break;
        }
        printf(",");
        if (!fwrite($dstStream, $line)) {
            throw new Exception("error writing to new file");
        }
        $linesCount--;
    }
    return $linesCount;
}




// Run the Patching process
try{
    $cwd = getcwd();
    chdir('common/framework/lib/module/');
    patch($cwd . '/patch.txt', 0);
}
catch(Exception $e) {
    echo "\n" . $e->getMessage();
}
exit("\n");
