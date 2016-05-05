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
			throws IOException {
		BufferedReader reader = openFile(filename);
		return parseReader(reader);
	}

	private static QuakeLogAnalyzedResults parseReader(BufferedReader reader)
			throws IOException {
		Map<String, Long> kills = new HashMap<String, Long>();
		Map<String, Long> killByMeans = new HashMap<String, Long>();
		long totalKills = 0; // assumed this count is same value as the sum of
								// the kill of all players

		while (reader.ready()) {
			String line = reader.readLine();
			Pattern pattern = Pattern.compile(LogPatterns.KILL);
			Matcher matcher = pattern.matcher(line);
			if (!matcher.matches()) {
				continue; // the line is not a death log.
			} else {
				String killer = matcher.group(0);
				String killed = matcher.group(0);
				String meanKill = matcher.group(0);
				if (killer.equals("<world>")) {
					--totalKills;
					// decrease number of kills of killed player
					updateHashMap(kills, killed, new Long(-1));
				} else {
					++totalKills;
					// increase number of kills of killer player
					updateHashMap(kills, killer, new Long(1));

					// update kill mean count
					updateHashMap(killByMeans, meanKill, new Long(1));
				}
			}
		}

		QuakeLogAnalyzedResults results = new QuakeLogAnalyzedResults(
				totalKills, kills, killByMeans);
		return results;
	}

	private static void updateHashMap(Map<String, Long> map, String obj, Long count) {
		long value = map.getOrDefault(obj, new Long(0));
		map.put(obj, value + count);
	}

	private static BufferedReader openFile(String filename)
			throws FileNotFoundException {
		return new BufferedReader(new InputStreamReader(new FileInputStream(
				filename)));
	}

}
