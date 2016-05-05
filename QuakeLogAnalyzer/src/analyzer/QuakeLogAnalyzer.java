package analyzer;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileNotFoundException;
import java.io.IOException;
import java.io.InputStreamReader;
import java.util.HashMap;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public class QuakeLogAnalyzer {

	public static QuakeLogAnalyzedResults parse(String filename)
			throws FileNotFoundException {
		BufferedReader reader = openFile(filename);
		return parseReader(reader);
	}

	private static QuakeLogAnalyzedResults parseReader(BufferedReader reader)
			throws IOException {
		Map<String, Long> kills = new HashMap<String, Long>();
		Map<String, Long> killByMeans = new HashMap<String, Long>();
		long totalKills = 0;

		while (reader.ready()) {
			String line = reader.readLine();
			Pattern pattern = Pattern
					.compile("\\b\\d?\\d?:\\d\\d Kill: \\d+ \\d+ \\d+: (<world>|\\w+[\\w ]*) killed (\\w+[\\w ]*) by \\w+\\b");
			Matcher matcher = pattern.matcher(line);
			if (!matcher.matches()) {
				continue; // the line is not a death log.
			} else {
				

			}
		}

	}

	private static BufferedReader openFile(String filename)
			throws FileNotFoundException {
		return new BufferedReader(new InputStreamReader(new FileInputStream(
				filename)));
	}

}
